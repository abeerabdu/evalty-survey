<?php

namespace Evalty\Survey\Http\Controllers;

use Evalty\Survey\Helpers\ApiResponse;
use Illuminate\Routing\Controller;
use Evalty\Survey\Models\Question;
use Evalty\Survey\Models\Survey;
use Evalty\Survey\Services\Survey\SurveyBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionController extends Controller
{
    public function __construct(
        protected SurveyBuilderService $surveyBuilder
    ) {}

    public function index(Survey $survey)
    {
        $questions = $survey->questions()->with('options')->orderBy('order')->get();

        return response()->json([
            'questions' => $questions,
        ]);
    }
    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'survey_id' => 'required|exists:surveys,id',
    //         'title' => 'required|array',
    //         'title.en' => 'nullable|string|max:255|required_if:default_lang,en',
    //         'title.ar' => 'nullable|string|max:255|required_if:default_lang,ar',
    //         'type' => 'required|string',
    //         'required' => 'boolean',
    //         'order' => 'integer',
    //         'options' => 'array',
    //         // 'options.*.label' => 'required_with:options|string|max:255',
    //         'options.*.label' => 'required|array',
    //         'options.*.label.en' => 'nullable|string|max:255|required_if:default_lang,en',
    //         'options.*.label.ar' => 'nullable|string|max:255|required_if:default_lang,ar',
    //         'options.*.value' => 'nullable|string|max:255',
    //         'options.*.order' => 'integer',
    //     ]);

    //     $question = Question::create([
    //         'survey_id' => $validated['survey_id'],
    //         'title' => $validated['title'],
    //         'type' => $validated['type'],
    //         'required' => $validated['required'] ?? false,
    //         'order' => $validated['order'] ?? 0,
    //     ]);

    //     if (!empty($validated['options'])) {
    //         foreach ($validated['options'] as $option) {
    //             $question->options()->create([
    //                 'label' => $option['label'],
    //                 'value' => $option['value'] ?? null,
    //                 'order' => $option['order'] ?? 0,
    //                 'is_correct' => $option['is_correct'] ?? false,
    //             ]);
    //         }
    //     }

    //     return response()->json($question->load('options'), 201);
    // }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'locale' => 'required|string',
            'title' => 'required|string',
            'type' => 'required|string',
            'required' => 'boolean',
            'order' => 'nullable|integer',
            'options' => 'nullable|array',
            'options.*.label' => 'required|string',
            'options.*.value' => 'nullable|string|max:255',
            'options.*.order' => 'integer',
            'options.*.is_correct' => 'boolean',
        ]);

        $question = $this->surveyBuilder->createQuestion($validated);

        return response()->json($question, 201);
    }

    public function updateTranslation(Request $request, Question $question)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'title' => 'sometimes|string',
        ]);

        $data = $this->surveyBuilder->updateQuestionTranslation($question, $validated);

        return ApiResponse::success($data, 'Translation updated successfully');
    }

    public function duplicate(Question $question)
    {
        $newQuestion = $question->duplicate();

        return ApiResponse::success($newQuestion, 'Question duplicate successfully');
    }

    public function update(Request $request, Question $question)
    {
        $question->update([
            'required' => $request->required,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Remove the specified question from storage.
     */
    public function destroy(Request $request, Question $question)
    {
        DB::transaction(function () use ($question) {
            $sectionId = $question->section_id;
            $deletedOrder = $question->order;

            $question->delete();

            Question::where('section_id', $sectionId)
                ->where('order', '>', $deletedOrder)
                ->decrement('order');
        });

        if ($request->wantsJson()) {
            return ApiResponse::success(
                true,
                'Question deleted successfully'
            );
        }

        return response()->json(['message' => 'Question deleted.']);
    }
}
