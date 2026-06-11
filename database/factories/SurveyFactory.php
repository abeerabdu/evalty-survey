<?php

namespace Evalty\Survey\Database\Factories;

use Evalty\Survey\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

class SurveyFactory extends Factory
{
    protected $model = Survey::class;

    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'status' => 'draft',
            'default_lang' => 'en',
        ];
    }
}
