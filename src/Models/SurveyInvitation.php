<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;


class SurveyInvitation extends Model
{
    use HasFactory;
    protected $fillable = [
        'survey_id',
        'email',
        'token',
        'expires_at',
        'has_responded',
        'responded_at',
        'accepted',
    ];

    protected function casts(): array
    {
        return [
            'has_responded' => 'boolean',
            'responded_at' => 'datetime',
            'accepted' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    // Check if invitation is expired
    public function isExpired(): bool
    {
        return $this->expires_at && now()->gt($this->expires_at);
    }

    // Mark as responded
    public function markAsResponded(): void
    {
        $this->update([
            'has_responded' => true,
            'responded_at' => now(),
        ]);
    }
    public function getInvitationError(): ?string
    {
        $locale = $this->default_lang;
        if ($this->isExpired()) {
            return 'This invitation has expired';
        }

        if ($this->has_responded) {
            return 'You have already submitted this survey';
        }

        return null;
    }
    protected static function booted()
    {
        static::creating(function ($invite) {
            if (!$invite->token) {
                $invite->token = Str::random(40);
            }
        });
    }
}
