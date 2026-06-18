<?php

namespace Evalty\Survey\Http\Controllers;

use Illuminate\Routing\Controller;
use Evalty\Survey\Models\Question;
use Evalty\Survey\Models\Option;
use Evalty\Survey\Models\Survey;
use Illuminate\Http\Request;
use Evalty\Survey\Helpers\ApiResponse;
use Evalty\Survey\Services\Survey\SurveyBuilderService;


class OptionController extends Controller
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

    public function store(Request $request, Question $question)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'label'  => 'required|string|max:255',
            'value'  => 'nullable|string|max:255',
        ]);

        $data = $this->surveyBuilder->createOption($question, $validated);

        return ApiResponse::success($data, 'Option created successfully');
    }


    public function update(Request $request, Question $question)
    {
        $rules = [];
        $request->merge([
            'title' => $request->title ?? [],
        ]);
        if ($request->has('title')) {

            $rules['title'] = 'sometimes|array';
            $rules['title.en'] = 'nullable|string|max:255';
            $rules['title.ar'] = 'nullable|string|max:255';
        }

        $validated = $request->validate($rules);
        if (isset($validated['title'])) {

            $validated['title'] = array_filter(
                $validated['title'],
                fn($v) => $v !== null && $v !== ''
            );

            $currentTitle = is_array($question->title)
                ? $question->title
                : [];

            $question->title = array_merge(
                $currentTitle,
                $validated['title']
            );
        }

        $question->save();

        return response()->json([
            'question' => $question->fresh()->only('title'),
            'message' => 'Question updated successfully',
        ]);
    }

    public function updateTranslation(Request $request, Option $option)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'label' => 'sometimes|string',
        ]);

        $data = $this->surveyBuilder->updateOptionTranslation($option, $validated);

        return ApiResponse::success($data, 'Translation updated successfully');
    }


    public function destroy(Request $request, Option $option)
    {
        $option->delete();
        if ($request->wantsJson()) {
            return ApiResponse::success(
                true,
                'Option deleted successfully'
            );
        }

        return response()->json(['message' => 'Option deleted.']);
    }
}
