<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Evalty\Survey\Models\Traits\HasTranslations;
use Evalty\Survey\Models\SectionTranslation;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use HasTranslations;
    protected $translationModel = SectionTranslation::class;

    protected $fillable = [
        'survey_id',
        'order',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)
            ->orderBy('order');
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
