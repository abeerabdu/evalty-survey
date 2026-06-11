<?php

namespace Evalty\Survey\Models\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;


/**
 * @mixin \Illuminate\Database\Eloquent\Model
 * @property \Illuminate\Database\Eloquent\Collection $translations
 * @property string|null $default_lang
 */
trait HasTranslations
{
    public function translations(): HasMany
    {
        return $this->hasMany($this->getTranslationModel());
    }

    protected function getTranslationModel()
    {
        return $this->translationModel;
    }

    public function getTranslation(string $field, ?string $locale = null)
    {
        $locale = $locale ?? app()->getLocale();

        $translation = $this->translations
            ->firstWhere('locale', $locale);

        if (!$translation && isset($this->default_lang)) {
            $translation = $this->translations
                ->firstWhere('locale', $this->default_lang);
        }

        return $translation?->$field;
    }

    public function setTranslation(string $locale, array $data)
    {
        return $this->translations()->updateOrCreate(
            ['locale' => $locale],
            $data
        );
    }

    public function setTranslations(string $field, array $translations)
    {
        foreach ($translations as $locale => $value) {

            // skip empty values
            if ($value === null || $value === '') {
                continue;
            }

            $this->translations()->updateOrCreate(
                ['locale' => $locale],
                [$field => $value]
            );
        }
    }

    public function getAllTranslations(string $field): array
    {
        return $this->translations
            ->pluck($field, 'locale')
            ->toArray();
    }
}
