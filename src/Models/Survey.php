<?php

namespace Evalty\Survey\Models;

// use Evalty\Survey\Database\Factories\SurveyFactory;
use Evalty\Survey\Models\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;

use RuntimeException;

class Survey extends Model
{
    use HasTranslations;

    protected $translationModel = SurveyTranslation::class;

    protected $fillable = [
        'tenant_id',
        'status',
        'logo',
        'published_at',
        'open_at',
        'close_at',
        'response_limit',
        'access_type',
        'uuid',
        'access_token',
        'default_lang',
        'user_id',
        'duplicated_from_id',
        'domain',
        'short_slug',
        'template_id'
    ];

    protected $casts = [
        'status' => 'string',
        'access_type' => 'string',
        'published_at' => 'datetime',
        'open_at' => 'datetime',
        'close_at' => 'datetime',
    ];

    /**
     * Normalise a semicolon/comma/whitespace-separated domain list for storage (ASCII LDH only).
     */
    public static function normaliseDomainCsv(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }
        $tokens = preg_split('/[;,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $seen = [];
        $out = [];
        foreach ($tokens as $raw) {
            $d = mb_strtolower(trim((string) $raw));
            $d = ltrim($d, '@');
            if ($d === '') {
                continue;
            }
            if (! str_contains($d, '.')) {
                continue;
            }
            if (filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                continue;
            }
            if (! isset($seen[$d])) {
                $seen[$d] = true;
                $out[] = $d;
            }
        }

        return implode(';', $out);
    }

    // public function tenant(): BelongsTo
    // {
    //     return $this->belongsTo(Tenant::class, 'tenant_id');
    // }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /**
     * Questions belong to sections (legacy direct survey_id on questions was removed).
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)
            ->orderBy('order');
    }
    public function directQuestions(): HasMany
    {
        return $this->hasMany(Question::class)
            ->whereNull('section_id')
            ->orderBy('order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    // public function owner(): BelongsTo
    // {
    //     return $this->belongsTo(User::class, 'user_id');
    // }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(
            Config::get('auth.providers.users.model'),
            'user_id'
        );
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SurveyTranslation::class);
    }

    public function template()
    {
        return $this->belongsTo(SurveyTemplate::class);
    }

    public function isClosed(): bool
    {
        if ($this->status === 'closed' || $this->hasReachedResponseLimit()) {
            return true;
        }

        if ($this->close_at && now()->greaterThan($this->close_at)) {
            return true;
        }

        return false;
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function hasReachedResponseLimit(): bool
    {
        if (! $this->response_limit || $this->response_limit === 0) {
            return false;
        }

        return $this->responses()->count() >= $this->response_limit;
    }

    public function translation($locale = null)
    {
        $locale = $locale ?? $this->default_lang;

        return $this->translations->firstWhere('locale', $locale);
    }
    public function getTitle($locale = null)
    {
        $locale = $locale ?? $this->default_lang;

        return $this->translations->firstWhere('locale', $locale)?->title
            ?? $this->translations->first()?->title
            ?? 'survey';
    }

    public function getMessage(string $key, $locale = null): string
    {
        $locale = $locale ?? $this->default_lang;

        $translation = $this->translation($locale);
        if ($translation && ! empty($translation->{$key})) {
            return $translation->{$key};
        }

        return config("survey.messages.$key.$locale", '');
    }

    public function getAccessError(string $type = 'public'): ?string
    {
        if ($type === 'public' && $this->access_type !== 'public') {
            return $this->getMessage('closed_message');
        }
        if ($type === 'private' && $this->access_type !== 'private') {
            return $this->getMessage('closed_message');
        }
        if ($this->status !== 'published') {
            return $this->getMessage('closed_message');
        }
        if ($this->open_at && now()->lt($this->open_at)) {
            return $this->getMessage('closed_message');
        }
        if ($this->close_at && now()->gt($this->close_at)) {
            return $this->getMessage('closed_message');
        }
        if ($this->isClosed()) {
            return $this->getMessage('closed_message');
        }

        return null;
    }

    public function getPrivateError(): ?string
    {
        return $this->getAccessError('private');
    }



    public function duplicate()
    {
        $this->loadMissing([
            'translations',
            'sections.translations',
            'sections.questions.translations',
            'sections.questions.options.translations',
        ]);

        return DB::transaction(function () {

            // Duplicate survey
            $newSurvey = $this->replicate();
            $newSurvey->uuid = Str::uuid();
            $newSurvey->status = 'draft';
            $newSurvey->duplicated_from_id = $this->id;
            $newSurvey->short_slug = null;
            $newSurvey->save();

            // Duplicate survey translations
            $this->translations->each(function ($translation) use ($newSurvey) {
                $newSurvey->translations()->create([
                    'locale' => $translation->locale,
                    'title' => $translation->locale === 'en'
                        ? $translation->title . ' (Copy)'
                        : $translation->title . ' (نسخة)',
                    'description' => $translation->description,
                    'welcome_message' => $translation->welcome_message,
                    'completed_message' => $translation->completed_message,
                    'closed_message' => $translation->closed_message,
                    'limit_message' => $translation->limit_message,
                ]);
            });

            // Duplicate sections
            $this->sections->each(function ($section) use ($newSurvey) {
                $newSection = $section->replicate();
                $newSection->survey_id = $newSurvey->id;
                $newSection->save();

                // Duplicate section translations
                $section->translations->each(function ($t) use ($newSection) {
                    $newSection->translations()->create([
                        'locale' => $t->locale,
                        'title' => $t->title,
                        'description' => $t->description,
                    ]);
                });

                // Duplicate questions inside the section
                $section->questions->each(function ($question) use ($newSection) {
                    $newQuestion = $question->replicate();
                    $newQuestion->section_id = $newSection->id;
                    $newQuestion->survey_id = $newSection->survey_id;
                    $newQuestion->save();

                    // Duplicate question translations
                    $question->translations->each(function ($t) use ($newQuestion) {
                        $newQuestion->translations()->create([
                            'locale' => $t->locale,
                            'title' => $t->title,
                        ]);
                    });

                    // Duplicate options inside question
                    $question->options->each(function ($option) use ($newQuestion) {
                        $newOption = $option->replicate();
                        $newOption->question_id = $newQuestion->id;
                        $newOption->save();

                        // Duplicate option translations
                        $option->translations->each(function ($ot) use ($newOption) {
                            $newOption->translations()->create([
                                'locale' => $ot->locale,
                                'title' => $ot->title,
                            ]);
                        });
                    });
                });
            });

            return $newSurvey;
        });
    }

    /**
     * @return list<string>
     */
    public function getAllowedDomainsAttribute(): array
    {
        $raw = $this->attributes['domain'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $tokens = preg_split('/[;,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $seen = [];
        $out = [];
        foreach ($tokens as $t) {
            $d = mb_strtolower(trim((string) $t));
            $d = ltrim($d, '@');
            if ($d === '') {
                continue;
            }
            if (! str_contains($d, '.')) {
                continue;
            }
            if (filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                continue;
            }
            if (! isset($seen[$d])) {
                $seen[$d] = true;
                $out[] = $d;
            }
        }

        return array_values($out);
    }

    public function isEmailAllowed(?string $email): bool
    {
        if ($email === null || trim($email) === '' || ! str_contains($email, '@')) {
            return false;
        }
        $lowerEmail = Str::lower(trim($email));
        $emailDomain = Str::lower(Str::after($lowerEmail, '@'));

        if ($this->invitations()->whereRaw('LOWER(email) = ?', [$lowerEmail])->exists()) {
            return true;
        }

        foreach ($this->allowed_domains as $domain) {
            if ($emailDomain === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Allocate and persist a unique short_slug for this tenant, or return existing (immutable).
     *
     * @throws RuntimeException
     */
    public function ensureShortSlug(): ?string
    {
        if ($this->short_slug) {
            return $this->short_slug;
        }

        $slug = $this->pickUniqueShortSlugCandidate();
        if ($slug === null) {
            throw new RuntimeException('Failed to allocate unique short_slug after 5 attempts');
        }

        $this->short_slug = $slug;

        try {
            $this->save();
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }
            $slug = $this->pickUniqueShortSlugCandidate();
            if ($slug === null) {
                throw new RuntimeException('Failed to allocate unique short_slug after duplicate collision retry');
            }
            $this->short_slug = $slug;
            $this->save();
        }

        return $this->short_slug;
    }

    /*==========================
      BOOT METHODS
    ==========================*/
    protected static function booted()
    {
        static::creating(function ($survey) {
            $survey->uuid = Str::uuid();
            if (Auth::check()) {
                $survey->user_id ??= Auth::id();
            }
            // $survey->user_id = Auth::id();
            // $survey->tenant_id = tenant()->id;
        });

        static::updating(function (Survey $survey) {
            if (! $survey->isDirty('short_slug')) {
                return;
            }
            $original = $survey->getOriginal('short_slug');
            if ($original !== null && $survey->short_slug !== $original) {
                throw new RuntimeException('Survey short link cannot be modified.');
            }
        });
    }

    private function pickUniqueShortSlugCandidate(): ?string
    {
        $tenantId = $this->tenant_id;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = Str::lower(Str::random(8));
            $exists = static::query()
                ->where('tenant_id', $tenantId)
                ->where('short_slug', $candidate)
                ->when($this->exists, fn($q) => $q->where('id', '!=', $this->id))
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return null;
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        $state = $e->errorInfo[0] ?? null;

        return $code === 1062 || $code === 19 || $state === '23000';
    }
    // protected static function newFactory()
    // {
    //     return SurveyFactory::new();
    // }
}
