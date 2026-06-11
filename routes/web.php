<?php

use Evalty\Survey\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Route;
// use Inertia\Inertia;

// Route::get(
//     uri: 'survey-generator',
//     action: function () {
//         return 'this is the survey package';
//     }
// );

Route::get('/survey-generator', [SurveyController::class, 'index']);
