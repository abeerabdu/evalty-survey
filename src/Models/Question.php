<?php

namespace Evalty\Survey\Models;

use Evalty\Survey\Models\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Question extends Model
{
    use HasTranslations;

    protected $translationModel = QuestionTranslation::class;

    protected $fillable = [

        'section_id',
        'survey_id',
        'display_index',
        'type',
        'required',
        'order',
    ];

    protected $casts = [
        'required' => 'boolean',
        // 'title' => 'array',
    ];

    //  Relationships

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function duplicate()
    {
        $this->loadMissing('translations', 'options.translations');

        return DB::transaction(function () {
            $newQuestion = $this->replicate();
            $newQuestion->order = $this->section
                ? ($this->section->questions()->max('order') ?? -1) + 1
                : (($this->order ?? 0) + 1);
            $newQuestion->save();

            foreach ($this->translations as $translation) {
                $newTranslation = $translation->replicate();
                $newTranslation->question_id = $newQuestion->id;
                $newTranslation->save();
            }

            foreach ($this->options as $option) {
                $newOption = $option->replicate();
                $newOption->question_id = $newQuestion->id;
                $newOption->save();

                foreach ($option->translations as $optionTranslation) {
                    $newOptionTranslation = $optionTranslation->replicate();
                    $newOptionTranslation->option_id = $newOption->id;
                    $newOptionTranslation->save();
                }
            }

            return $newQuestion->load('translations', 'options.translations');
        });
    }

    protected static function booted()
    {
        static::creating(function ($question) {

            if (!$question->survey_id && $question->section_id) {

                $question->survey_id = Section::find(
                    $question->section_id
                )?->survey_id;
            }
        });
    }
}
