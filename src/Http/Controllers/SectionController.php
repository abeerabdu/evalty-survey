<?php

namespace Evalty\Survey\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Evalty\Survey\Models\Survey;
use Evalty\Survey\Models\Section;
use Evalty\Survey\Helpers\ApiResponse;
use Illuminate\Support\Facades\DB;
use Evalty\Survey\Services\Survey\SurveyBuilderService;




class SectionController extends Controller
{
    public function __construct(
        protected SurveyBuilderService $surveyBuilder
    ) {}
    public function store(Request $request, Survey $survey)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'title' => 'required|string',
        ]);

        $section = DB::transaction(function () use ($survey, $validated) {

            // 1️⃣ create section
            $section = $survey->sections()->create([
                'order' => $survey->sections()->count(),
            ]);

            // 2️⃣ add translation
            $section->setTranslations('title', [
                $validated['locale'] => $validated['title']
            ]);

            return $section->load('translations');
        });

        return ApiResponse::success($section, 'Section created');
    }

    public function updateTranslation(Request $request, Section $section)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'title' => 'sometimes|string',
        ]);

        $data = $this->surveyBuilder->updateSectionTranslation($section, $validated);

        return ApiResponse::success($data, 'Translation updated successfully');
    }

    public function duplicate(Section $section)
    {
        $data = $this->surveyBuilder->duplicateSection($section);

        return ApiResponse::success($data, 'Section duplicated successfully');
    }

    public function destroy(Section $section)
    {
        $this->surveyBuilder->deleteSection($section);

        return ApiResponse::success(null, 'Section deleted successfully');
    }
}
