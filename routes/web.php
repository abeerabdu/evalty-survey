<?php

use Evalty\Survey\Http\Controllers\SurveyController;
use Evalty\Survey\Http\Controllers\OptionController;
use Evalty\Survey\Http\Controllers\QuestionController;
use Evalty\Survey\Http\Controllers\ResponseController;
use Evalty\Survey\Http\Controllers\SectionController;
use Illuminate\Support\Facades\Route;
// use Inertia\Inertia;

// Route::get(
//     uri: 'survey-generator',
//     action: function () {
//         return 'this is the survey package';
//     }
// );

Route::get('/survey-generator', [SurveyController::class, 'index'])->name('surveyh');

Route::prefix('survey-builder')->group(function () {

    Route::get('/', [SurveyController::class, 'index']);
    Route::get('/test', fn() => 'package works');

    Route::get('/survey', [SurveyController::class, 'index'])->name('survey');
    Route::get('survey/{survey}', [SurveyController::class, 'getDetails'])->name('survey.details');
    Route::get('survey/{survey}/invitations', [SurveyController::class, 'invitations'])->name('survey.invitations');
    Route::get('survey/{survey}/translations', [SurveyController::class, 'translationTree'])->name('survey.translations');
    Route::get('surveyForm', [SurveyController::class, 'create'])->name('surveyForm');
    Route::post('survey', [SurveyController::class, 'store'])->name('survey.store');
    Route::put('survey/{survey}', [SurveyController::class, 'update'])->name('survey.update');
    Route::put('survey/{survey}/publish', [SurveyController::class, 'publish'])->name('survey.publish');
    Route::post('survey/{survey}/short-link', [SurveyController::class, 'ensureShortLink'])->name('survey.short-link');
    Route::put('survey/{survey}/close', [SurveyController::class, 'close'])->name('survey.close');
    Route::post('survey/{survey}/updateImage', [SurveyController::class, 'updateImage'])->name('survey.updateImage');
    Route::delete('survey/{survey}/destroy', [SurveyController::class, 'destroy'])->name('survey.destroy');
    Route::get('survey/{survey}/manage', [SurveyController::class, 'manage'])->name('survey.manage');
    Route::get('survey/{survey}/review', [SurveyController::class, 'review'])->name('survey.review');
    Route::put('survey/{survey}/redraft', [SurveyController::class, 'redraft'])->name('survey.redraft');
    Route::post('survey/{survey}/submit', [SurveyController::class, 'submit'])->name('survey.submit');
    Route::get('survey/{survey}/people', [SurveyController::class, 'peopleWithAccess'])->name('survey.peopleWithAccess');
    Route::get('survey/{survey}/defaults', [SurveyController::class, 'getDefaults'])->name('survey.defaults');
    Route::put('survey/{survey}/settings', [SurveyController::class, 'updateSettings'])->name('survey.settings');
    Route::post('surveys/{survey}/duplicate', [SurveyController::class, 'duplicate'])->name('survey.duplicate');
    Route::put('survey/{survey}/languages', [SurveyController::class, 'updateLanguages'])->name('survey.languages');
    Route::put('survey/{survey}/translation', [SurveyController::class, 'updateTranslation'])
        ->name('survey.translation.update');
    Route::get('survey/{survey}/messages', [SurveyController::class, 'getSurveyMessages'])->name('survey.messages');
    Route::put('survey/{survey}/updateMessages', [SurveyController::class, 'updateMessages'])
        ->name('survey.messages.update');
    Route::put('survey/{survey}/builder', [SurveyController::class, 'saveBuilder'])->name('survey.builder');


    Route::post('survey/{survey}/sections', [SectionController::class, 'store'])
        ->name('section.store');
    Route::put('section/{section}/translation', [SectionController::class, 'updateTranslation'])
        ->name('section.translation.update');
    Route::post('section/{section}/duplicate', [SectionController::class, 'duplicate'])->name('section.duplicate');
    Route::delete('section/{section}/destroy', [SectionController::class, 'destroy'])->name('section.destroy');

    Route::post('question', [QuestionController::class, 'store'])->name('question.store');
    Route::post('question/{question}/duplicate', [QuestionController::class, 'duplicate'])->name('question.duplicate');
    Route::put('question/{question}/update', [QuestionController::class, 'update'])->name('question.update');
    Route::put('question/{question}/translation', [QuestionController::class, 'updateTranslation'])
        ->name('question.translation.update');

    Route::post('question/{question}/option', [OptionController::class, 'store'])->name('option.store');

    Route::put('option/{option}/translation', [OptionController::class, 'updateTranslation'])
        ->name('option.translation.update');
    Route::delete('option/{option}/destroy', [OptionController::class, 'destroy'])->name('option.destroy');

    Route::delete('question/{question}', [QuestionController::class, 'destroy'])->name('question.destroy');
    Route::put('question/bulk-update', [QuestionController::class, 'bulkUpdate'])->name('question.bulk-update');

    Route::get('response', [ResponseController::class, 'index'])->name('survey.responses');

    Route::get('response/{survey}/export', [ResponseController::class, 'export'])
        ->name('response.export');
});
// Route::middleware('auth')->as('tenant.')->group(function () {
// Route::middleware(['web', 'auth', 'verified'])
//     ->prefix('survey')
//     ->as('survey.')
//     ->group(function () {

// Route::redirect('survey', 'survey/index');


        // /response?survey=5
    // });
