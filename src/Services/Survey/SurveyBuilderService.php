<?php

namespace Evalty\Survey\Services\Survey;

use Evalty\Survey\Models\Option;
use Evalty\Survey\Models\Question;
use Evalty\Survey\Models\Section;
use Evalty\Survey\Models\Survey;
use Evalty\Survey\Models\SurveyTemplate;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Survey payload contracts (deterministic first paint):
 *
 * - {@see self::getDetailsForAdmin()} — Tenant `GET survey/{id}` (Inertia Details). Includes `languages` and full
 *   `survey.translations` rows; nested `sections.*.translations` / `questions.*.translations` / `options.*.translations`
 *   are **default_lang only** until merged via {@see self::getDetailsForTranslations()}. **Never** includes `invitations`.
 * - {@see self::getDetailsForPublic()} — Public respondent page. `translations` and nested translation arrays include
 *   at most **active locale + default_lang** (server-side fallback). No invitations.
 * - {@see self::getDetailsForTranslations()} — Lazy JSON for merging full multilingual nested translations into admin UI.
 */
class SurveyBuilderService
{
    public function mapCollection($surveys)
    {
        return $surveys->map(function (Survey $survey) {
            return [
                'id' => $survey->id,
                'title' => $survey->getTranslation('title'),
                'description' => $survey->getTranslation('description'),
                'status' => $survey->status,
                'created_at' => $survey->created_at,
                'logo' => $survey->logo,
                'default_lang' => $survey->default_lang,
                'logo_url' => $this->getLogoUrl($survey),
            ];
        });
    }

    public function map(Survey $survey): array
    {
        return [
            'id' => $survey->id,
            'title' => $survey->getTranslation('title'),
            'description' => $survey->getTranslation('description'),
            'status' => $survey->status,
            'default_lang' => $survey->default_lang,
            'created_at' => $survey->created_at,
            'logo_url' => $this->getLogoUrl($survey),
        ];
    }

    /**
     * @deprecated Use {@see self::getDetailsForAdmin()} — will be removed after one release.
     *
     * @return array<string, mixed>
     */
    public function getDetails(int $surveyId): array
    {
        return $this->getDetailsForAdmin($surveyId);
    }

    /**
     * Tenant survey Details first paint: no invitations; nested translations default_lang only (merge full via {@see self::getDetailsForTranslations()}).
     *
     * @return array<string, mixed>
     */
    public function getDetailsForAdmin(int $surveyId): array
    {
        $survey = $this->loadSurveyGraphWithoutInvitations($surveyId);
        $default = (string) $survey->default_lang;

        return array_merge($this->surveyScalarPayload($survey), [
            'translations' => $this->mapSurveyTranslationRows($survey->translations),
            'languages' => $this->getOrderedLanguages($survey),
            'sections' => $this->mapSectionsForAdminDefaultLocale($survey, $default),
            'direct_questions' => $this->mapDirectQuestions(
                $survey,
                $default
            ),
            'builder_items' => $this->buildBuilderItemsFromSurvey($survey, $default),
        ]);
    }

    /**
     * Interleaved builder tree: sections and direct questions in global order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildBuilderItemsFromSurvey(Survey $survey, string $default): array
    {
        $entries = [];

        foreach ($this->mapSectionsForAdminDefaultLocale($survey, $default) as $section) {
            $entries[] = [
                'order' => (int) ($section['order'] ?? 0),
                'item' => [
                    'type' => 'section',
                    'id' => $section['id'],
                    'order' => $section['order'],
                    'translations' => $section['translations'],
                    'questions' => $section['questions'],
                ],
            ];
        }

        foreach ($this->mapDirectQuestions($survey, $default) as $question) {
            $entries[] = [
                'order' => (int) ($question['order'] ?? 0),
                'item' => [
                    'type' => 'direct_question',
                    'question' => $question,
                ],
            ];
        }

        usort($entries, fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static fn(array $entry): array => $entry['item'], $entries);
    }
    private function mapDirectQuestions(
        Survey $survey,
        string $default
    ): array {

        return $survey->directQuestions
            ->sortBy('order')
            ->map(function (Question $question) use ($default): array {

                return [
                    'id' => $question->id,
                    'title' => $question->getTranslation(
                        'title',
                        $default
                    ),
                    'type' => $question->type,
                    'required' => $question->required,
                    'order' => $question->order,
                    'translations' => $this->filterRowsToLocale(
                        $question->translations,
                        $default,
                        'question'
                    ),
                    'options' => $question->options
                        ->sortBy('order')
                        ->map(function (Option $opt) use ($default): array {
                            return [
                                'id' => $opt->id,
                                'value' => $opt->value,
                                'order' => $opt->order,
                                'label' => $opt->getTranslation(
                                    'label',
                                    $default
                                ),
                                'translations' => $this->filterRowsToLocale(
                                    $opt->translations,
                                    $default,
                                    'option'
                                ),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Public respondent payload: active locale + default_lang only (server-side), no invitations.
     *
     * @return array<string, mixed>
     */
    /**
     * Tenant survey review page: flat questions + per-locale title/description/labels (legacy Inertia shape).
     *
     * @return array<string, mixed>
     */
    public function buildTenantSurveyReviewPayload(int $surveyId, string $activeLocale): array
    {
        $surveyPayload = $this->getDetailsForPublic($surveyId, $activeLocale);
        $defaultLang = (string) ($surveyPayload['default_lang'] ?? 'en');

        $surveyPayload['title'] = collect($surveyPayload['translations'] ?? [])
            ->mapWithKeys(fn($t) => [$t['locale'] => $t['title'] ?? ''])
            ->all();
        $surveyPayload['description'] = collect($surveyPayload['translations'] ?? [])
            ->mapWithKeys(fn($t) => [$t['locale'] => $t['description'] ?? ''])
            ->all();

        $surveyPayload['questions'] = collect($surveyPayload['sections'] ?? [])
            ->sortBy('order')
            ->flatMap(fn($section) => collect($section['questions'] ?? [])->sortBy('order'))
            ->map(function (array $question) use ($defaultLang) {
                $titles = collect($question['translations'] ?? [])
                    ->mapWithKeys(fn($t) => [$t['locale'] => $t['title'] ?? ''])
                    ->all();
                if ($titles === [] && isset($question['title'])) {
                    $titles[$defaultLang] = $question['title'];
                }
                $question['title'] = $titles;

                $question['options'] = collect($question['options'] ?? [])
                    ->map(function (array $opt) use ($defaultLang) {
                        $labels = collect($opt['translations'] ?? [])
                            ->mapWithKeys(fn($t) => [$t['locale'] => $t['label'] ?? ''])
                            ->all();
                        if ($labels === [] && isset($opt['label'])) {
                            $labels[$defaultLang] = $opt['label'];
                        }
                        $opt['label'] = $labels;

                        return $opt;
                    })
                    ->values()
                    ->all();

                return $question;
            })
            ->values()
            ->all();

        return $surveyPayload;
    }

    public function getDetailsForPublic(int $surveyId, string $activeLocale): array
    {
        $survey = $this->loadSurveyGraphWithoutInvitations($surveyId);
        $default = (string) $survey->default_lang;
        $available = $survey->translations->pluck('locale')->map(fn($l) => (string) $l)->unique()->values()->all();
        if (! in_array($activeLocale, $available, true)) {
            $activeLocale = $default;
        }
        $locales = array_values(array_unique([$activeLocale, $default]));

        return array_merge($this->surveyScalarPayload($survey), [
            'translations' => $this->filterSurveyTranslationsForLocales($survey->translations, $locales),
            'languages' => $locales,
            'sections' => $this->mapSectionsForPublicLocales($survey, $activeLocale, $default, $locales),
            'direct_questions' => $this->mapDirectQuestions(
                $survey,
                $default
            ),
        ]);
    }

    /**
     * Full multilingual nested translations for lazy merge into admin UI (no invitations).
     *
     * @return array{translations: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>}
     */
    public function getDetailsForTranslations(int $surveyId): array
    {
        $survey = $this->loadSurveyGraphWithoutInvitations($surveyId);

        return [
            'translations' => $this->mapSurveyTranslationRows($survey->translations),
            'sections' => $survey->sections
                ->sortBy('order')
                ->map(fn(Section $section): array => [
                    'id' => $section->id,
                    'translations' => $section->translations->map(fn($t) => [
                        'id' => $t->id,
                        'locale' => $t->locale,
                        'title' => $t->title,
                    ])->values()->all(),
                    'questions' => $section->questions
                        ->sortBy('order')
                        ->map(fn(Question $question): array => [
                            'id' => $question->id,
                            'translations' => $question->translations->map(fn($t) => [
                                'id' => $t->id,
                                'locale' => $t->locale,
                                'title' => $t->title,
                            ])->values()->all(),
                            'options' => $question->options
                                ->sortBy('order')
                                ->map(fn(Option $opt): array => [
                                    'id' => $opt->id,
                                    'translations' => $opt->translations->map(fn($t) => [
                                        'id' => $t->id,
                                        'locale' => $t->locale,
                                        'label' => $t->label,
                                    ])->values()->all(),
                                ])
                                ->values()
                                ->all(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function getOrderedLanguages(Survey $survey)
    {
        return $survey->translations()
            ->orderByRaw('
        CASE
            WHEN locale = ? THEN 0
            ELSE 1
        END
        ', [$survey->default_lang])
            ->orderBy('created_at')
            ->pluck('locale')
            ->values();
    }

    public function createWithTranslations(array $data): Survey
    {
        return DB::transaction(function () use ($data) {
            $template = SurveyTemplate::query()
                ->where('key', $data['template_key'])
                ->firstOrFail();

            $survey = Survey::create([
                'status' => 'draft',
                'default_lang' => $data['default_lang'],
                'template_id' => $template->id,
            ]);

            $locale = $data['default_lang'];

            $survey->setTranslation($locale, [
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
            ]);

            return $survey->load('translations');
        });
    }
    public function applyTemplate(Survey $survey): void
    {
        $survey->load('template');

        $templateKey = $survey->template?->key;

        match ($templateKey) {

            'google_form' => $this->buildGoogleFormTemplate($survey),

            'default_form' => null,

            default => null,
        };
    }
    protected function buildGoogleFormTemplate(Survey $survey): void
    {
        DB::transaction(function () use ($survey) {

            $locale = $survey->default_lang;

            /*
            |--------------------------------------------------------------------------
            | Rating Question
            |--------------------------------------------------------------------------
            */

            $rating = $survey->questions()->create([
                'survey_id' => $survey->id,
                'section_id' => null,
                'type' => 'rating',
                'required' => true,
                'order' => 1,
            ]);

            $rating->setTranslation($locale, [
                'title' => 'Rate your experience',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Review Text Question
            |--------------------------------------------------------------------------
            */

            $review = $survey->questions()->create([
                'survey_id' => $survey->id,
                'section_id' => null,
                'type' => 'textarea',
                'required' => false,
                'order' => 2,
            ]);

            $review->setTranslation($locale, [
                'title' => 'Share details of your experience',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Upload Media Question
            |--------------------------------------------------------------------------
            */

            // $media = $survey->questions()->create([
            //     'survey_id' => $survey->id,

            //     'section_id' => null,

            //     'type' => 'file_upload',

            //     'is_required' => false,

            //     'order' => 3,
            // ]);

            // $media->setTranslation($locale, [
            //     'title' => 'Add photos & videos',
            // ]);
        });
    }

    public function updateWithTranslations(Survey $survey, array $data): Survey
    {
        return DB::transaction(function () use ($survey, $data) {

            $survey->update([
                'default_lang' => $data['default_lang'],
            ]);

            $locale = $data['default_lang'];

            $survey->setTranslation($locale, [
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
            ]);

            return $survey->load('translations');
        });
    }

    public function save(array $data): Survey
    {
        return DB::transaction(function () use ($data) {

            $survey = Survey::updateOrCreate(
                ['id' => $data['id'] ?? null],
                [
                    'uuid' => $data['uuid'] ?? Str::uuid(),
                    'languages' => $data['languages'],
                    'default_language' => $data['default_language'] ?? 'en',
                    'status' => $data['status'] ?? 'draft',
                ]
            );

            $this->syncSurveyTranslations($survey, $data['title'], $data['description'] ?? []);
            return $survey->fresh(['pages.questions.options', 'translations']);
        });
    }

    public function updateTranslation(Survey $survey, array $data): array
    {
        return DB::transaction(function () use ($survey, $data) {

            // 1️⃣ Get or create translation
            $translation = $survey->translations()->firstOrCreate([
                'locale' => $data['locale'],
            ]);

            // 2️⃣ Extract only allowed fields
            $updateData = collect($data)
                ->only(['title', 'description'])
                ->toArray();

            // 3️⃣ Update only if something exists
            if (! empty($updateData)) {
                $translation->update($updateData);
            }

            return [
                'locale' => $translation->locale,
                'title' => $translation->title,
                'description' => $translation->description,
            ];
        });
    }

    public function getSurveyMessages(int $surveyId): array
    {
        $survey = Survey::with('translations')
            ->where('id', $surveyId)
            ->firstOrFail();

        return $survey->translations->map(function ($t) {
            return [
                'locale' => $t->locale,
                'welcome_message' => $t->welcome_message ?? null,
                'completed_message' => $t->completed_message ?? null,
                'closed_message' => $t->closed_message ?? null,
                'limit_message' => $t->limit_message ?? null,
            ];
        })->values()->toArray();
    }

    public function updateSurveyMessages(Survey $survey, array $data): array
    {
        return DB::transaction(function () use ($survey, $data) {

            $locale = $data['locale'];
            $messages = $data['messages'];

            // get or create translation
            $translation = $survey->translations()->firstOrCreate([
                'locale' => $locale,
            ]);

            // only allowed message fields
            $allowedKeys = array_keys(config('survey.messages'));

            $updateData = [];

            foreach ($allowedKeys as $key) {
                if (array_key_exists($key, $messages)) {
                    $updateData[$key] = trim($messages[$key] ?? '') ?: null;
                }
            }

            if (! empty($updateData)) {
                $translation->update($updateData);
            }

            return [
                'locale' => $translation->locale,
                'messages' => $updateData,
            ];
        });
    }

    public function createQuestion(array $data): Question
    {
        return DB::transaction(function () use ($data) {

            $section = Section::query()->findOrFail($data['section_id']);

            $attributes = [
                'section_id' => $section->id,
                'type' => $data['type'],
                'required' => $data['required'] ?? false,
                'order' => $data['order'] ?? 0,
            ];

            if (Schema::hasColumn((new Question)->getTable(), 'survey_id')) {
                $attributes['survey_id'] = $section->survey_id;
            }

            $nextDisplay = $this->nextQuestionDisplayIndexForSurvey($section);
            if ($nextDisplay !== null) {
                $attributes['display_index'] = $nextDisplay;
            }

            // ✅ 1. Create Question
            $question = Question::create($attributes);

            // 🟢 New format (single)
            if (! empty($data['title']) && ! empty($data['locale'])) {
                $question->translations()->updateOrCreate(
                    ['locale' => $data['locale']],
                    ['title' => $data['title']]
                );
            }

            // ✅ 3. Options
            foreach ($data['options'] ?? [] as $optData) {

                $option = $question->options()->create([
                    'value' => $optData['value'] ?? null,
                    'order' => $optData['order'] ?? 0,
                    'is_correct' => $optData['is_correct'] ?? false,
                ]);

                // 🔥 SAME FIX FOR OPTIONS
                if (isset($optData['label'])) {

                    if (! empty($optData['label']) && ! empty($data['locale'])) {
                        $option->translations()->updateOrCreate(
                            ['locale' => $data['locale']],
                            ['label' => $optData['label']]
                        );
                    }
                }
            }

            return $question->load('translations', 'options.translations');
        });
    }

    public function updateQuestionTranslation(Question $question, array $data): array
    {
        return DB::transaction(function () use ($question, $data) {

            // 1️⃣ Get or create translation with default title
            $translation = $question->translations()->firstOrCreate(
                ['locale' => $data['locale']],
                ['title' => $data['title'] ?? ''] // provide a default non-null value
            );

            // 2️⃣ Update only allowed fields
            $updateData = collect($data)
                ->only(['title'])
                ->toArray();

            if (! empty($updateData)) {
                $translation->update($updateData);
            }

            return [
                'locale' => $translation->locale,
                'title' => $translation->title,
            ];
        });
    }

    public function updateOptionTranslation(Option $option, array $data): array
    {
        return DB::transaction(function () use ($option, $data) {

            $translation = $option->translations()->firstOrCreate(
                ['locale' => $data['locale']],
                ['label' => $data['label'] ?? ''] // provide a default non-null value
            );

            // 2️⃣ Update only allowed fields
            $updateData = collect($data)
                ->only(['label'])
                ->toArray();

            if (! empty($updateData)) {
                $translation->update($updateData);
            }

            return [
                'locale' => $translation->locale,
                'label' => $translation->label,
            ];
        });
    }

    /**
     *  survey sections
     */
    public function updateSectionTranslation(Section $section, array $data): array
    {
        return DB::transaction(function () use ($section, $data) {

            // 1️⃣ Get or create translation with default title
            $translation = $section->translations()->firstOrCreate(
                ['locale' => $data['locale']],
                ['title' => $data['title'] ?? ''] // provide a default non-null value
            );

            // 2️⃣ Update only allowed fields
            $updateData = collect($data)
                ->only(['title'])
                ->toArray();

            if (! empty($updateData)) {
                $translation->update($updateData);
            }

            return [
                'locale' => $translation->locale,
                'title' => $translation->title,
            ];
        });
    }

    public function duplicateSection(Section $section): Section
    {
        return DB::transaction(function () use ($section) {

            $section->load([
                'translations',
                'questions.translations',
                'questions.options.translations',
            ]);

            // 1️⃣ clone section
            $newSection = $section->replicate();
            $newSection->order = $section->order + 1;
            $newSection->push();

            // 2️⃣ copy translations
            foreach ($section->translations as $translation) {
                $newSection->translations()->create([
                    'locale' => $translation->locale,
                    'title' => $translation->title,
                ]);
            }

            // 3️⃣ duplicate questions
            foreach ($section->questions as $question) {

                $newQuestion = $question->replicate();
                $newQuestion->section_id = $newSection->id;
                $newQuestion->push();

                // question translations
                foreach ($question->translations as $qTranslation) {
                    $newQuestion->translations()->create([
                        'locale' => $qTranslation->locale,
                        'title' => $qTranslation->title,
                    ]);
                }

                // options
                foreach ($question->options as $option) {
                    $newOption = $option->replicate();
                    $newOption->question_id = $newQuestion->id;
                    $newOption->push();

                    foreach ($option->translations as $oTranslation) {
                        $newOption->translations()->create([
                            'locale' => $oTranslation->locale,
                            'title' => $oTranslation->title,
                        ]);
                    }
                }
            }

            return $newSection->load([
                'translations',
                'questions.translations',
                'questions.options.translations',
            ]);
        });
    }

    public function deleteSection(Section $section): void
    {
        DB::transaction(function () use ($section) {

            $section->load('questions.options.translations', 'questions.translations', 'translations');

            foreach ($section->questions as $question) {

                foreach ($question->options as $option) {
                    $option->translations()->delete();
                }

                $question->options()->delete();
                $question->translations()->delete();
            }

            $section->questions()->delete();
            $section->translations()->delete();

            $section->delete();
        });
    }

    /**
     *  survey option
     */
    public function createOption(Question $question, array $data): Option
    {
        return DB::transaction(function () use ($question, $data) {

            // clean label (extra safety)
            $label = trim($data['label']);

            if ($label === '') {
                throw new Exception('Label cannot be empty');
            }

            $rawVal = isset($data['value']) ? trim((string) $data['value']) : '';
            $value = $rawVal === '' ? (string) Str::ulid() : $rawVal;
            if ($question->options()->where('value', $value)->exists()) {
                throw ValidationException::withMessages([
                    'value' => ['This export key is already used for another option.'],
                ]);
            }

            // 1️⃣ create option
            $option = $question->options()->create([
                'value' => $value,
                'order' => $question->options()->count(), // auto ترتيب
            ]);

            // 2️⃣ add translation
            $option->setTranslations('label', [
                $data['locale'] => $label,
            ]);

            return $option->load('translations');
        });
    }

    public function updateLanguages(Survey $survey, array $languages): void
    {
        DB::transaction(function () use ($survey, $languages) {

            $languages = collect($languages);

            // ✅ Ensure at least one language
            if ($languages->isEmpty()) {
                throw new Exception('At least one language is required');
            }

            // ✅ Ensure ONE default
            if (! $languages->contains('is_default', true)) {
                $languages = $languages->values()->map(function ($lang, $index) {
                    return [
                        'code' => $lang['code'],
                        'is_default' => $index === 0,
                    ];
                });
            }

            $defaultSet = false;

            $languages = $languages->map(function ($lang) use (&$defaultSet) {

                if (($lang['is_default'] ?? false) && ! $defaultSet) {
                    $defaultSet = true;

                    return [
                        'code' => $lang['code'],
                        'is_default' => true,
                    ];
                }

                return [
                    'code' => $lang['code'],
                    'is_default' => false,
                ];
            });

            // ✅ Remove duplicates (VERY IMPORTANT)
            $languages = $languages->unique('code')->values();

            // ✅ Delete old
            $survey->languages()->delete();

            // ✅ Insert new
            foreach ($languages as $lang) {
                $survey->languages()->create($lang);
            }

            // ✅ Update survey default_lang
            $default = $languages->firstWhere('is_default', true);

            if ($default) {
                $survey->update([
                    'default_lang' => $default['code'],
                ]);
            }
        });
    }

    /**
     * Validate before publish
     */
    public function validateForPublish(Survey $survey): void
    {
        $languages = $survey->languages;

        foreach ($survey->pages as $page) {
            foreach ($page->questions as $question) {

                foreach ($languages as $lang) {

                    $exists = $question->translations()
                        ->where('locale', $lang)
                        ->exists();

                    if (! $exists) {
                        throw new Exception("Missing question translation: {$lang}");
                    }
                }

                // options validation
                if (in_array($question->type, ['radio', 'checkbox', 'select'])) {

                    foreach ($question->options as $option) {
                        foreach ($languages as $lang) {

                            $exists = $option->translations()
                                ->where('locale', $lang)
                                ->exists();

                            if (! $exists) {
                                throw new Exception("Missing option translation: {$lang}");
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Publish survey
     */
    public function publish(Survey $survey): Survey
    {
        $this->validateForPublish($survey);

        $survey->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $survey;
    }

    /**
     * Sync survey translations
     */
    protected function syncSurveyTranslations(Survey $survey, array $titles, array $descriptions)
    {
        foreach ($titles as $locale => $title) {

            $survey->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'title' => $title,
                    'description' => $descriptions[$locale] ?? null,
                ]
            );
        }
    }


    /**
     * Question translations
     */
    protected function syncQuestionTranslations(Question $question, array $titles, array $descriptions)
    {
        foreach ($titles as $locale => $title) {

            $question->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'title' => $title,
                    'description' => $descriptions[$locale] ?? null,
                ]
            );
        }
    }

    private function loadSurveyGraphWithoutInvitations(int $surveyId): Survey
    {
        return Survey::query()
            ->with([
                'template',
                'translations',
                'sections.translations',
                'sections.questions.translations',
                'sections.questions.options.translations',
                'directQuestions.translations',
                'directQuestions.options.translations',
            ])
            ->where('id', $surveyId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function surveyScalarPayload(Survey $survey): array
    {

        $default = (string) $survey->default_lang;

        return [
            'id' => $survey->id,
            'title' => $survey->getTranslation('title', $default),
            'description' => $survey->getTranslation('description', $default),
            'status' => $survey->status,
            'access_type' => $survey->access_type,
            'domain' => $survey->domain ?? '',
            'short_slug' => $survey->short_slug,
            'default_lang' => $survey->default_lang,
            'created_at' => $survey->created_at,
            'uuid' => $survey->uuid,
            'open_at' => $survey->open_at?->toIso8601String(),
            'close_at' => $survey->close_at?->toIso8601String(),
            'response_limit' => $survey->response_limit,
            'published_at' => $survey->published_at?->toIso8601String(),
            'template_key' => $survey->template?->key,
            'template_name' => $survey->template?->name,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapSurveyTranslationRows(Collection $rows): array
    {
        return $rows->map(fn($t): array => [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'welcome_message' => $t->welcome_message,
            'completed_message' => $t->completed_message,
            'closed_message' => $t->closed_message,
            'limit_message' => $t->limit_message,
            'locale' => $t->locale,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @param  array<int, string>  $locales
     * @return array<int, array<string, mixed>>
     */
    private function filterSurveyTranslationsForLocales(Collection $rows, array $locales): array
    {
        return $rows
            ->filter(fn($t): bool => in_array((string) $t->locale, $locales, true))
            ->map(fn($t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'welcome_message' => $t->welcome_message,
                'completed_message' => $t->completed_message,
                'closed_message' => $t->closed_message,
                'limit_message' => $t->limit_message,
                'locale' => $t->locale,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapSectionsForAdminDefaultLocale(Survey $survey, string $default): array
    {
        return $survey->sections
            ->sortBy('order')
            ->map(function (Section $section) use ($default): array {
                return [
                    'id' => $section->id,
                    'order' => $section->order,
                    'title' => $section->getTranslation('title', $default),
                    'translations' => $this->filterRowsToLocale($section->translations, $default, 'section'),
                    'questions' => $section->questions
                        ->sortBy('order')
                        ->map(function (Question $question) use ($default): array {
                            return [
                                'id' => $question->id,
                                'title' => $question->getTranslation('title', $default),
                                'type' => $question->type,
                                'required' => $question->required,
                                'order' => $question->order,
                                'translations' => $this->filterRowsToLocale($question->translations, $default, 'question'),
                                'options' => $question->options
                                    ->sortBy('order')
                                    ->map(function (Option $opt) use ($default): array {
                                        return [
                                            'id' => $opt->id,
                                            'value' => $opt->value,
                                            'order' => $opt->order,
                                            'label' => $opt->getTranslation('label', $default),
                                            'translations' => $this->filterRowsToLocale($opt->translations, $default, 'option'),
                                        ];
                                    })
                                    ->values()
                                    ->all(),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $locales
     * @return array<int, array<string, mixed>>
     */
    private function mapSectionsForPublicLocales(Survey $survey, string $active, string $default, array $locales): array
    {
        return $survey->sections
            ->sortBy('order')
            ->map(function (Section $section) use ($active, $default, $locales): array {
                return [
                    'id' => $section->id,
                    'order' => $section->order,
                    'title' => $section->getTranslation('title', $active) ?: $section->getTranslation('title', $default),
                    'translations' => $this->filterRowsToLocales($section->translations, $locales, 'section'),
                    'questions' => $section->questions
                        ->sortBy('order')
                        ->map(function (Question $question) use ($active, $default, $locales): array {
                            $title = $question->getTranslation('title', $active) ?: $question->getTranslation('title', $default);

                            return [
                                'id' => $question->id,
                                'title' => $title,
                                'type' => $question->type,
                                'required' => $question->required,
                                'order' => $question->order,
                                'translations' => $this->filterRowsToLocales($question->translations, $locales, 'question'),
                                'options' => $question->options
                                    ->sortBy('order')
                                    ->map(function (Option $opt) use ($active, $default, $locales): array {
                                        $label = $opt->getTranslation('label', $active) ?: $opt->getTranslation('label', $default);

                                        return [
                                            'id' => $opt->id,
                                            'value' => $opt->value,
                                            'order' => $opt->order,
                                            'label' => $label,
                                            'translations' => $this->filterRowsToLocales($opt->translations, $locales, 'option'),
                                        ];
                                    })
                                    ->values()
                                    ->all(),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterRowsToLocale(Collection $rows, string $locale, string $kind): array
    {
        return $rows
            ->where('locale', $locale)
            ->values()
            ->map(function ($t) use ($kind): array {
                if ($kind === 'option') {
                    return [
                        'id' => $t->id,
                        'locale' => $t->locale,
                        'label' => $t->label,
                    ];
                }

                return [
                    'id' => $t->id,
                    'locale' => $t->locale,
                    'title' => $t->title,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @param  array<int, string>  $locales
     * @return array<int, array<string, mixed>>
     */
    private function filterRowsToLocales(Collection $rows, array $locales, string $kind): array
    {
        return $rows
            ->filter(fn($t): bool => in_array((string) $t->locale, $locales, true))
            ->values()
            ->map(function ($t) use ($kind): array {
                if ($kind === 'option') {
                    return [
                        'id' => $t->id,
                        'locale' => $t->locale,
                        'label' => $t->label,
                    ];
                }

                return [
                    'id' => $t->id,
                    'locale' => $t->locale,
                    'title' => $t->title,
                ];
            })
            ->all();
    }

    private function getLogoUrl(Survey $survey): ?string
    {
        if (! $survey->logo) {
            return null;
        }

        // return tenant()
        //     ? tenant_asset($survey->logo)
        //     : asset('storage/' . $survey->logo);
        return asset('storage/' . $survey->logo);
    }

    /**
     * Next global display_index for questions in this survey (tenant DB may require the column).
     */
    private function nextQuestionDisplayIndexForSurvey(Section $section): ?int
    {
        $table = (new Question)->getTable();
        if (! Schema::hasColumn($table, 'display_index')) {
            return null;
        }

        $surveyId = $section->survey_id;

        if (Schema::hasColumn($table, 'survey_id')) {
            $max = Question::query()->where('survey_id', $surveyId)->max('display_index');
        } else {
            $max = Question::query()
                ->whereHas('section', fn($q) => $q->where('survey_id', $surveyId))
                ->max('display_index');
        }

        return ((int) $max) + 1;
    }

    // public function saveBuilder(Survey $survey, array $data): Survey
    // {
    //     return DB::transaction(function () use ($survey, $data) {

    //         /*
    //     |--------------------------------------------------------------------------
    //     | DIRECT QUESTIONS
    //     |--------------------------------------------------------------------------
    //     */

    //         $directQuestionIds = [];

    //         foreach ($data['direct_questions'] ?? [] as $index => $qData) {

    //             $isTemp = str($qData['id'])->startsWith('temp_');

    //             if ($isTemp) {

    //                 $question = Question::create([
    //                     'survey_id' => $survey->id,
    //                     'section_id' => null,
    //                     'type' => $qData['type'],
    //                     'required' => $qData['required'] ?? false,
    //                     'order' => $index,
    //                 ]);
    //             } else {

    //                 $question = Question::updateOrCreate(
    //                     [
    //                         'id' => $qData['id'],
    //                     ],
    //                     [
    //                         'survey_id' => $survey->id,
    //                         'section_id' => null,
    //                         'type' => $qData['type'],
    //                         'required' => $qData['required'] ?? false,
    //                         'order' => $index,
    //                     ]
    //                 );
    //             }

    //             $directQuestionIds[] = $question->id;

    //             /*
    //         |--------------------------------------------------------------------------
    //         | QUESTION TRANSLATIONS
    //         |--------------------------------------------------------------------------
    //         */

    //             foreach ($qData['translations'] ?? [] as $translation) {

    //                 $question->translations()->updateOrCreate(
    //                     [
    //                         'locale' => $translation['locale'],
    //                     ],
    //                     [
    //                         'title' => $translation['title'],
    //                     ]
    //                 );
    //             }

    //             /*
    //         |--------------------------------------------------------------------------
    //         | OPTIONS
    //         |--------------------------------------------------------------------------
    //         */

    //             $optionIds = [];

    //             foreach ($qData['options'] ?? [] as $optIndex => $optData) {

    //                 $option = $question->options()->updateOrCreate(
    //                     [
    //                         'id' => is_numeric($optData['id'] ?? null)
    //                             ? $optData['id']
    //                             : null,
    //                     ],
    //                     [
    //                         'value' => $optData['value'] ?? Str::uuid(),
    //                         'order' => $optIndex,
    //                     ]
    //                 );

    //                 $optionIds[] = $option->id;

    //                 foreach ($optData['translations'] ?? [] as $translation) {

    //                     $option->translations()->updateOrCreate(
    //                         [
    //                             'locale' => $translation['locale'],
    //                         ],
    //                         [
    //                             'label' => $translation['label'],
    //                         ]
    //                     );
    //                 }
    //             }

    //             /*
    //         |--------------------------------------------------------------------------
    //         | DELETE REMOVED OPTIONS
    //         |--------------------------------------------------------------------------
    //         */

    //             $question->options()
    //                 ->whereNotIn('id', $optionIds)
    //                 ->delete();
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | DELETE REMOVED DIRECT QUESTIONS
    //     |--------------------------------------------------------------------------
    //     */

    //         Question::query()
    //             ->where('survey_id', $survey->id)
    //             ->whereNull('section_id')
    //             ->whereNotIn('id', $directQuestionIds)
    //             ->delete();

    //         /*
    //     |--------------------------------------------------------------------------
    //     | SECTIONS
    //     |--------------------------------------------------------------------------
    //     */

    //         $sectionIds = [];

    //         foreach ($data['sections'] ?? [] as $sectionIndex => $sectionData) {
    //             $isTemp = str($sectionData['id'])->startsWith('temp_');
    //             if ($isTemp) {

    //                 $section = Section::create([
    //                     'survey_id' => $survey->id,
    //                     'order' => $sectionIndex,
    //                 ]);
    //             } else {

    //                 $section = Section::updateOrCreate(
    //                     [
    //                         'id' => $sectionData['id'],
    //                     ],
    //                     [
    //                         'survey_id' => $survey->id,
    //                         'order' => $sectionIndex,
    //                     ]
    //                 );
    //             }
    //             $sectionIds[] = $section->id;

    //             /*
    //         |--------------------------------------------------------------------------
    //         | SECTION TRANSLATIONS
    //         |--------------------------------------------------------------------------
    //         */

    //             foreach ($sectionData['translations'] ?? [] as $translation) {

    //                 $section->translations()->updateOrCreate(
    //                     [
    //                         'locale' => $translation['locale'],
    //                     ],
    //                     [
    //                         'title' => $translation['title'],
    //                     ]
    //                 );
    //             }

    //             /*
    //         |--------------------------------------------------------------------------
    //         | SECTION QUESTIONS
    //         |--------------------------------------------------------------------------
    //         */

    //             $questionIds = [];

    //             foreach ($sectionData['questions'] ?? [] as $qIndex => $qData) {

    //                 $question = Question::updateOrCreate(
    //                     [
    //                         'id' => is_numeric($qData['id'] ?? null)
    //                             ? $qData['id']
    //                             : null,
    //                     ],
    //                     [
    //                         'survey_id' => $survey->id,
    //                         'section_id' => $section->id,
    //                         'type' => $qData['type'],
    //                         'required' => $qData['required'] ?? false,
    //                         'order' => $qIndex,
    //                     ]
    //                 );

    //                 $questionIds[] = $question->id;

    //                 /*
    //             |--------------------------------------------------------------------------
    //             | QUESTION TRANSLATIONS
    //             |--------------------------------------------------------------------------
    //             */

    //                 foreach ($qData['translations'] ?? [] as $translation) {

    //                     $question->translations()->updateOrCreate(
    //                         [
    //                             'locale' => $translation['locale'],
    //                         ],
    //                         [
    //                             'title' => $translation['title'],
    //                         ]
    //                     );
    //                 }

    //                 /*
    //             |--------------------------------------------------------------------------
    //             | OPTIONS
    //             |--------------------------------------------------------------------------
    //             */

    //                 $optionIds = [];

    //                 foreach ($qData['options'] ?? [] as $optIndex => $optData) {

    //                     $option = $question->options()->updateOrCreate(
    //                         [
    //                             'id' => is_numeric($optData['id'] ?? null)
    //                                 ? $optData['id']
    //                                 : null,
    //                         ],
    //                         [
    //                             'value' => $optData['value'] ?? Str::uuid(),
    //                             'order' => $optIndex,
    //                         ]
    //                     );

    //                     $optionIds[] = $option->id;

    //                     foreach ($optData['translations'] ?? [] as $translation) {

    //                         $option->translations()->updateOrCreate(
    //                             [
    //                                 'locale' => $translation['locale'],
    //                             ],
    //                             [
    //                                 'label' => $translation['label'],
    //                             ]
    //                         );
    //                     }
    //                 }

    //                 /*
    //             |--------------------------------------------------------------------------
    //             | DELETE REMOVED OPTIONS
    //             |--------------------------------------------------------------------------
    //             */

    //                 $question->options()
    //                     ->whereNotIn('id', $optionIds)
    //                     ->delete();
    //             }

    //             /*
    //         |--------------------------------------------------------------------------
    //         | DELETE REMOVED QUESTIONS
    //         |--------------------------------------------------------------------------
    //         */

    //             $section->questions()
    //                 ->whereNotIn('id', $questionIds)
    //                 ->delete();
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | DELETE REMOVED SECTIONS
    //     |--------------------------------------------------------------------------
    //     */

    //         $survey->sections()
    //             ->whereNotIn('id', $sectionIds)
    //             ->delete();

    //         return $survey->fresh([
    //             'sections.questions.options.translations',
    //             'sections.questions.translations',
    //             'sections.translations',
    //             'directQuestions.options.translations',
    //             'directQuestions.translations',
    //         ]);
    //     });
    // }
    // public function saveBuilder(Survey $survey, array $data): Survey
    // {
    //     return DB::transaction(function () use ($survey, $data) {

    //         $this->saveDirectQuestions(
    //             $survey,
    //             $data['direct_questions'] ?? []
    //         );

    //         $this->saveSections(
    //             $survey,
    //             $data['sections'] ?? []
    //         );

    //         $survey->refresh();

    //         $survey->load([
    //             'sections.questions.options.translations',
    //             'sections.questions.translations',
    //             'sections.translations',
    //             'directQuestions.options.translations',
    //             'directQuestions.translations',
    //         ]);

    //         return $survey;
    //     });
    // }
    /**
     * @return array<string, mixed>
     */
    public function saveBuilder(Survey $survey, array $data): array
    {
        return DB::transaction(function () use ($survey, $data) {

            $items = $data['builder_items'] ?? [];

            $sectionIds = [];
            $questionIds = [];

            foreach ($items as $index => $item) {

                if ($item['type'] === 'section') {

                    $section = $this->saveSection(
                        survey: $survey,
                        data: $item,
                        order: $index
                    );

                    $sectionIds[] = $section->id;

                    foreach ($item['questions'] ?? [] as $qIndex => $q) {

                        $question = $this->saveQuestion(
                            survey: $survey,
                            data: $q,
                            order: $qIndex,
                            sectionId: $section->id
                        );

                        $questionIds[] = $question->id;
                    }
                }

                if ($item['type'] === 'direct_question') {

                    $question = $this->saveQuestion(
                        survey: $survey,
                        data: $item['question'],
                        order: $index,
                        sectionId: null
                    );

                    $questionIds[] = $question->id;
                }
            }

            // cleanup
            $survey->sections()
                ->whereNotIn('id', $sectionIds)
                ->delete();

            Question::where('survey_id', $survey->id)
                ->whereNotIn('id', $questionIds)
                ->delete();

            return $this->getDetailsForAdmin($survey->id);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | DIRECT QUESTIONS
    |--------------------------------------------------------------------------
    */

    protected function saveDirectQuestions(
        Survey $survey,
        array $questions
    ): void {

        $ids = [];

        foreach ($questions as $index => $qData) {

            $question = $this->saveQuestion(
                survey: $survey,
                data: $qData,
                order: $index,
                sectionId: null
            );

            $ids[] = $question->id;
        }

        Question::query()
            ->where('survey_id', $survey->id)
            ->whereNull('section_id')
            ->whereNotIn('id', $ids)
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | SECTIONS
    |--------------------------------------------------------------------------
    */

    protected function saveSections(
        Survey $survey,
        array $sections
    ): void {

        $sectionIds = [];

        foreach ($sections as $sectionIndex => $sectionData) {

            $section = $this->saveSection(
                survey: $survey,
                data: $sectionData,
                order: $sectionIndex
            );

            $sectionIds[] = $section->id;

            $this->saveSectionQuestions(
                survey: $survey,
                section: $section,
                questions: $sectionData['questions'] ?? []
            );
        }

        $survey->sections()
            ->whereNotIn('id', $sectionIds)
            ->delete();
    }

    protected function saveSection(
        Survey $survey,
        array $data,
        int $order
    ): Section {

        if ($this->isTempId($data['id'])) {

            $section = Section::create([
                'survey_id' => $survey->id,
                'order' => $order,
            ]);
        } else {

            $section = Section::updateOrCreate(
                [
                    'id' => $data['id'],
                ],
                [
                    'survey_id' => $survey->id,
                    'order' => $order,
                ]
            );
        }

        $this->syncTranslations(
            model: $section,
            translations: $data['translations'] ?? [],
            field: 'title'
        );

        return $section;
    }

    /*
    |--------------------------------------------------------------------------
    | SECTION QUESTIONS
    |--------------------------------------------------------------------------
    */

    protected function saveSectionQuestions(
        Survey $survey,
        Section $section,
        array $questions
    ): void {

        $questionIds = [];

        foreach ($questions as $index => $qData) {

            $question = $this->saveQuestion(
                survey: $survey,
                data: $qData,
                order: $index,
                sectionId: $section->id
            );

            $questionIds[] = $question->id;
        }

        $section->questions()
            ->whereNotIn('id', $questionIds)
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | QUESTION
    |--------------------------------------------------------------------------
    */

    protected function saveQuestion(
        Survey $survey,
        array $data,
        int $order,
        ?int $sectionId
    ): Question {

        if ($this->isTempId($data['id'])) {

            $question = Question::create([
                'survey_id' => $survey->id,
                'section_id' => $sectionId,
                'type' => $data['type'],
                'required' => $data['required'] ?? false,
                'order' => $order,
            ]);
        } else {

            $question = Question::updateOrCreate(
                [
                    'id' => $data['id'],
                ],
                [
                    'survey_id' => $survey->id,
                    'section_id' => $sectionId,
                    'type' => $data['type'],
                    'required' => $data['required'] ?? false,
                    'order' => $order,
                ]
            );
        }

        $this->syncTranslations(
            model: $question,
            translations: $data['translations'] ?? [],
            field: 'title'
        );

        $this->saveOptions(
            question: $question,
            options: $data['options'] ?? []
        );

        return $question;
    }

    /*
    |--------------------------------------------------------------------------
    | OPTIONS
    |--------------------------------------------------------------------------
    */

    protected function saveOptions(
        Question $question,
        array $options
    ): void {

        $optionIds = [];

        foreach ($options as $index => $optData) {

            if ($this->isTempId($optData['id'])) {

                $option = $question->options()->create([
                    'value' => $optData['value'] ?? Str::uuid(),
                    'order' => $index,
                ]);
            } else {

                $option = $question->options()->updateOrCreate(
                    [
                        'id' => $optData['id'],
                    ],
                    [
                        'value' => $optData['value'] ?? Str::uuid(),
                        'order' => $index,
                    ]
                );
            }

            $optionIds[] = $option->id;
            $this->syncTranslations(
                model: $option,
                translations: $optData['translations'] ?? [],
                field: 'label'
            );
        }

        $question->options()
            ->whereNotIn('id', $optionIds)
            ->delete();
    }


    protected function syncTranslations(
        $model,
        array $translations,
        string $field
    ): void {
        foreach ($translations as $translation) {
            $model->translations()->updateOrCreate(
                [
                    'locale' => $translation['locale'],
                ],
                [
                    $field => $translation[$field],
                ]
            );
        }
    }

    protected function isTempId(mixed $id): bool
    {
        return str($id)->startsWith('temp_');
    }
}
