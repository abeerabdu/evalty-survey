<?php

namespace Evalty\Survey\Http\Controllers;


use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Evalty\Survey\Models\Response;
use Evalty\Survey\Models\Survey;
use Inertia\Inertia;
use Evalty\Survey\Helpers\ApiResponse;
use Evalty\Survey\Services\Survey\ResponseService;
use Evalty\Survey\Exports\SurveyResponsesExport;
use Maatwebsite\Excel\Facades\Excel;

class ResponseController extends Controller
{
    public function __construct(
        private ResponseService $responseService
    ) {}

    public function index(Request $request)
    {
        $data = $this->responseService->getSurveyAnalytics($request);

        if ($request->wantsJson()) {
            return ApiResponse::success($data['summary']);
        }

        return Inertia::render('tenant/survey/surveyResponses', [
            'survey' => $data['survey'],
            'summary' => $data['summary'],
            'pagination' => $data['pagination'],
        ]);
    }


    public function export(int|string $surveyId)
    {
        $survey = Survey::with('translations')->findOrFail($surveyId);
        $surveyName = $survey->getTitle();

        $safeName = preg_replace('/[^A-Za-z0-9\pL\pN\s_-]/u', '_', $surveyName);

        $safeName = preg_replace('/[\s_]+/', '_', $safeName);

        $safeName = trim($safeName, '_');

        // 4. fallback if empty
        if (empty($safeName)) {
            $safeName = 'survey';
        }

        $fileName = $safeName . '_' . now()->format('Y-m-d_H-i-s') . '.xlsx';

        return Excel::download(
            new SurveyResponsesExport($surveyId),
            $fileName
        );
    }
}
