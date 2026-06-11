<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\HasTranslations;


class Option extends Model
{
    use HasTranslations;
    protected $translationModel = OptionTranslation::class;

    protected $fillable = [
        'question_id',
        // 'label',
        'value',
        'order',
    ];

    protected $casts = [
        'label' => 'array',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
