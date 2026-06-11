<?php

namespace Evalty\Survey\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Response extends Model
{
    protected $fillable = [
        'survey_id',
        'user_id',
        'tenant_id',
        'ip_address',
        'started_at',
        'submitted_at',
        'duration_seconds',
        'submitted_locale',
        'email',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    //  Relationships

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    //  Helpers

    public function isSubmitted(): bool
    {
        return ! is_null($this->submitted_at);
    }
    // protected static function booted()
    // {
    //     static::creating(function ($response) {

    //         if (tenant()) {
    //             $response->tenant_id = tenant()->id; // ✅ FIX
    //         }

    //         // $response->user_id = Auth::id();
    //     });
    // }
}
