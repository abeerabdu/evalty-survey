<?php

namespace Evalty\Survey\Providers;

use Illuminate\Support\ServiceProvider;

class SurveyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');

        $this->loadMigrationsFrom(
            __DIR__ . '/../../database/migrations'
        );

        $this->publishes([
            __DIR__ . '/../../config/survey.php'
            => config_path('survey.php'),
        ], 'survey-config');
    }
}
