<?php

namespace Evalty\Survey\Services\Survey;

use Evalty\Survey\Models\Response;
use Evalty\Survey\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Evalty\Survey\Services\Survey\AnswerSnapshotBuilder;
use Evalty\Survey\Models\Answer;


class ResponseService
{
    public function getSurveyAnalytics(Request $request): array
    {
        $survey = $this->getSurvey($request);
        $surveyId = $survey->id;
        $locale = $survey->default_lang;

        // ✅ FAST MAP
        $questionsMap = $survey->sections
            ->flatMap->questions
            ->merge($survey->directQuestions)
            ->keyBy('id');

        // ✅ PAGINATION (lightweight)
        $responses = $this->getPaginatedResponses($surveyId);

        // ✅ BASE STATS (ONE QUERY)
        $baseStats = $this->getBaseStats($surveyId);

        // ✅ TIME SERIES (DB optimized)
        $timeSeries = $this->getResponsesOverTime($surveyId);

        // ✅ QUESTION CHARTS (DB aggregation 🚀)
        $questionStats = $this->getQuestionStats($surveyId);
        $questionCharts = $this->buildQuestionCharts($questionStats, $questionsMap, $locale);

        return [
            'survey' => $survey,
            'summary' => [
                'total_responses' => (int) $baseStats->total_responses,
                'average_time' => gmdate('H:i', (int) $baseStats->avg_seconds),
                'duration' => max(
                    1,
                    (int) Carbon::parse($baseStats->start_date)
                        ->diffInDays(Carbon::parse($baseStats->end_date))
                ),
                'responses_over_time' => $timeSeries,
                'questions' => $questionCharts,
                'responses' => $this->getDetailedResponses($surveyId, $questionsMap, $locale),
            ],
            'pagination' => [
                'current_page' => $responses->currentPage(),
                'last_page' => $responses->lastPage(),
                'per_page' => $responses->perPage(),
                'total' => $responses->total(),
            ],
        ];
    }

    // =========================
    // 📌 Survey
    // =========================
    private function getSurvey(Request $request): Survey
    {
        $surveyId = $request->get('survey')
            ?? $request->get('survey_id')
            ?? $request->get('id');

        abort_if(!$surveyId, 400, 'Survey not specified.');

        return Survey::with([
            'translations',
            'sections.questions.options.translations',
            'sections.questions.translations',
            'directQuestions.translations',
            'directQuestions.options.translations',
        ])->findOrFail($surveyId);
    }

    // =========================
    // 📌 Pagination (light)
    // =========================
    private function getPaginatedResponses(int $surveyId)
    {
        return Response::where('survey_id', $surveyId)
            ->select(['id', 'user_id', 'submitted_at'])
            ->with(['user:id,name,email'])
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->paginate(15);
    }

    // =========================
    // 📌 Base Stats (ONE QUERY)
    // =========================
    private function getBaseStats(int $surveyId)
    {
        return Response::where('survey_id', $surveyId)
            ->whereNotNull('submitted_at')
            ->selectRaw('
                COUNT(*) as total_responses,
                AVG(EXTRACT(EPOCH FROM (submitted_at - started_at))) as avg_seconds,
                MIN(started_at) as start_date,
                MAX(submitted_at) as end_date
            ')
            ->first();
    }

    // =========================
    // 📌 Time Series (PostgreSQL 🚀)
    // =========================
    // private function getResponsesOverTime(int $surveyId): array
    // {
    //     $rows = DB::select("
    //         SELECT
    //             gs::date as date,
    //             COALESCE(COUNT(r.id), 0) as total
    //         FROM generate_series(
    //             (SELECT MIN(DATE(submitted_at)) FROM responses WHERE survey_id = ?),
    //             (SELECT MAX(DATE(submitted_at)) FROM responses WHERE survey_id = ?),
    //             interval '1 day'
    //         ) gs
    //         LEFT JOIN responses r
    //             ON DATE(r.submitted_at) = gs::date
    //             AND r.survey_id = ?
    //         GROUP BY gs
    //         ORDER BY gs
    //     ", [$surveyId, $surveyId, $surveyId]);

    //     return [
    //         'labels' => collect($rows)->pluck('date'),
    //         'values' => collect($rows)->pluck('total'),
    //     ];
    // }
    private function getResponsesOverTime(int $surveyId): array
    {
        $data = Response::where('survey_id', $surveyId)
            ->whereNotNull('submitted_at')
            ->selectRaw('DATE(submitted_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        if ($data->isEmpty()) {
            return ['labels' => [], 'values' => []];
        }

        // ✅ FIX: better range (last 30 days)
        $end = Carbon::now();
        $start = Carbon::now()->subDays(30);

        $period = CarbonPeriod::create($start, $end);

        $labels = [];
        $values = [];

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');

            $labels[] = $key;
            $values[] = $data[$key] ?? 0;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    private function getQuestionStats(int $surveyId)
    {
        return Answer::query()
            ->whereHas('response', function ($q) use ($surveyId) {
                $q->where('survey_id', $surveyId)
                    ->whereNotNull('submitted_at');
            })
            ->select(['question_id', 'value'])
            ->get();
    }

    // private function getDetailedResponses(int $surveyId, $questionsMap, string $locale): array
    // {
    //     $questionCount = max(1, $questionsMap->count());

    //     return Response::query()
    //         ->where('survey_id', $surveyId)
    //         ->whereNotNull('submitted_at')
    //         ->select([
    //             'id',
    //             'user_id',
    //             'started_at',
    //             'submitted_at',
    //             'duration_seconds',
    //             'submitted_locale',
    //             'email',
    //         ])
    //         ->with([
    //             'user:id,name,email',
    //             'answers.question.translations',
    //         ])
    //         ->orderByDesc('submitted_at')
    //         ->get()
    //         ->map(function (Response $response) use ($questionsMap, $locale, $questionCount) {
    //             $answers = $response->answers
    //                 ->map(function (Answer $answer) use ($questionsMap, $locale) {
    //                     $question = $questionsMap->get($answer->question_id) ?? $answer->question;

    //                     return [
    //                         'question_id' => $answer->question_id,
    //                         'question_title' => $question
    //                             ? $this->getQuestionTitleForLocale($question, $locale)
    //                             : 'Untitled',
    //                         'type' => $question?->type,
    //                         'value' => $this->formatAnswerValue($answer),
    //                     ];
    //                 })
    //                 ->values()
    //                 ->all();

    //             $answeredCount = count($answers);
    //             $durationSeconds = $response->duration_seconds;

    //             if ($durationSeconds === null && $response->started_at && $response->submitted_at) {
    //                 $durationSeconds = (int) $response->started_at->diffInSeconds($response->submitted_at);
    //             }

    //             return [
    //                 'id' => $response->id,
    //                 'respondent_name' => $response->user?->name
    //                     ?? $response->email
    //                     ?? 'Anonymous',
    //                 'respondent_email' => $response->user?->email
    //                     ?? $response->email
    //                     ?? '',
    //                 'submitted_at' => $response->submitted_at,
    //                 'started_at' => $response->started_at,
    //                 'submitted_locale' => $response->submitted_locale,
    //                 'duration_seconds' => $durationSeconds,
    //                 'answered_count' => $answeredCount,
    //                 'completion_percentage' => (int) round(($answeredCount / $questionCount) * 100),
    //                 'answers' => $answers,
    //             ];
    //         })
    //         ->values()
    //         ->all();
    // }
    private function getDetailedResponses(
        int $surveyId,
        $questionsMap,
        string $locale
    ): array {

        $responses = Response::query()
            ->where('survey_id', $surveyId)
            ->whereNotNull('submitted_at')
            ->select([
                'id',
                'user_id',
                'started_at',
                'submitted_at',
                'duration_seconds',
                'submitted_locale',
                'email',
            ])
            ->with([
                'user:id,name,email',
                'answers.question.translations',
            ])
            ->orderByDesc('submitted_at')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Question Headers
        |--------------------------------------------------------------------------
        */

        $headers = [];

        foreach ($questionsMap as $question) {
            $headers[] = $this->getQuestionTitleForLocale(
                $question,
                $locale
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Meta Headers
        |--------------------------------------------------------------------------
        */
        $metaHeaders = [
            [
                'key' => 'respondent_name',
                'title' => 'Respondent Name',
            ],
            [
                'key' => 'respondent_email',
                'title' => 'Respondent Email',
            ],
            [
                'key' => 'submitted_at',
                'title' => 'Submitted At',
            ],
            [
                'key' => 'started_at',
                'title' => 'Started At',
            ],
            [
                'key' => 'duration_seconds',
                'title' => 'Duration',
            ],
            [
                'key' => 'submitted_locale',
                'title' => 'Locale',
            ],
        ];
        // $metaHeaders = [
        //     'respondent_name',
        //     'respondent_email',
        //     'submitted_at',
        //     'started_at',
        //     'duration_seconds',
        //     'submitted_locale',
        // ];

        /*
        |--------------------------------------------------------------------------
        | Rows
        |--------------------------------------------------------------------------
        */

        $data = $responses->map(function (
            Response $response
        ) use (
            $questionsMap,
            $locale
        ) {

            /*
            |--------------------------------------------------------------------------
            | Answers Row
            |--------------------------------------------------------------------------
            */

            $answersRow = [];

            // initialize empty values
            foreach ($questionsMap as $question) {

                $questionTitle = $this->getQuestionTitleForLocale(
                    $question,
                    $locale
                );

                $answersRow[$questionTitle] = null;
            }

            // fill answers
            foreach ($response->answers as $answer) {

                $question = $questionsMap->get($answer->question_id)
                    ?? $answer->question;

                if (! $question) {
                    continue;
                }

                $questionTitle = $this->getQuestionTitleForLocale(
                    $question,
                    $locale
                );

                $answersRow[$questionTitle] = $this->formatAnswerValue($answer);
            }

            /*
            |--------------------------------------------------------------------------
            | Meta Data
            |--------------------------------------------------------------------------
            */

            $meta = [
                'respondent_name' => $response->user?->name
                    ?? $response->email
                    ?? 'Anonymous',

                'respondent_email' => $response->user?->email
                    ?? $response->email
                    ?? '',

                'submitted_at' => $response->submitted_at,

                'started_at' => $response->started_at,

                'duration_seconds' => $response->duration_seconds,

                'submitted_locale' => $response->submitted_locale,
            ];

            return [
                'answers' => $answersRow,
                'meta' => $meta,
            ];
        })->values()->all();

        return [
            'headers' => $headers,
            'meta_headers' => $metaHeaders,
            'data' => $data,
        ];
    }

    private function buildQuestionCharts($answers, $questionsMap, $locale): array
    {
        $result = [];

        foreach ($questionsMap as $questionId => $question) {

            $rows = $answers->where('question_id', $questionId);

            $type = $question->type;
            $chartType = $this->detectChartType($type);

            $title = $question->translations
                ->firstWhere('locale', $locale)?->title
                ?? $question->title;

            // =========================
            // 🧠 FLATTEN ANSWERS
            // =========================
            $rawAnswers = [];

            foreach ($rows as $answer) {
                $value = AnswerSnapshotBuilder::decodeStoredMachineValue($answer->value);

                if (is_array($value)) {
                    foreach ($value as $v) {
                        $rawAnswers[] = $v;
                    }
                } else {
                    $rawAnswers[] = $value;
                }
            }

            $rawAnswers = array_values(array_filter($rawAnswers, fn($v) => $v !== null && $v !== ''));

            // =========================
            // ❌ TEXT QUESTIONS
            // =========================
            if (! $chartType) {
                $result[] = [
                    'question_id' => $questionId,
                    'question_title' => $title,
                    'type' => $type,
                    'chart' => null,
                    'answers' => $rawAnswers,
                ];
                continue;
            }

            // =========================
            // 📊 GROUP + SORT
            // =========================
            $counter = collect($rawAnswers)
                ->countBy()
                ->sortDesc();

            $total = $counter->sum();

            $answersFormatted = $counter->map(function ($count, $value) {
                return [
                    'value' => $value,
                    'count' => $count,
                ];
            })->values()->all();

            // =========================
            // 📊 CHART DATA
            // =========================
            $labels = $counter->keys()->values();
            $series = $counter->values();

            $percentages = $counter->map(function ($count) use ($total) {
                return (int) round(($count / max($total, 1)) * 100);
            })->values();

            // =========================
            // 📦 RESULT
            // =========================
            $result[] = [
                'question_id' => $questionId,
                'question_title' => $title,
                'type' => $type,

                'chart' => [
                    'type' => $chartType,
                    'labels' => $labels,
                    'series' => $series,
                    'percentages' => $percentages,
                ],

                // ✅ CLEAN ANSWERS (what you wanted)
                'answers' => $answersFormatted,
            ];
        }

        return $result;
    }
    private function detectChartType(string $type): ?string
    {
        return match ($type) {
            'select', 'radio' => 'pie',
            'checkbox' => 'bar',
            'rating' => 'column',
            'text', 'textarea' => null,
            default => 'bar',
        };
    }

    private function getQuestionTitleForLocale($question, string $locale): string
    {
        return $question->translations->firstWhere('locale', $locale)?->title
            ?? $question->title
            ?? 'Untitled';
    }

    private function formatAnswerValue(Answer $answer): array|string|null
    {
        $decoded = AnswerSnapshotBuilder::decodeStoredMachineValue($answer->value);

        if (is_array($decoded)) {
            $values = array_values(array_filter(
                array_map(fn($value) => is_scalar($value) ? (string) $value : null, $decoded),
                fn($value) => $value !== null && $value !== ''
            ));

            if (! empty($values)) {
                return $values;
            }
        }

        if (is_scalar($decoded) && $decoded !== '') {
            return (string) $decoded;
        }

        $label = json_decode($answer->label_snapshot ?? '', true);

        if (is_array($label)) {
            $values = array_values(array_filter(
                array_map(fn($value) => is_scalar($value) ? (string) $value : null, $label),
                fn($value) => $value !== null && $value !== ''
            ));

            return empty($values) ? null : $values;
        }

        if (is_scalar($label) && $label !== '') {
            return (string) $label;
        }

        return null;
    }

    private function getQuestionAnswers(int $surveyId, $questionsMap, $locale): array
    {
        $answers = Answer::query()
            ->whereHas('response', function ($q) use ($surveyId) {
                $q->where('survey_id', $surveyId)
                    ->whereNotNull('submitted_at');
            })
            ->select(['question_id', 'value'])
            ->get();

        $result = [];

        foreach ($questionsMap as $questionId => $question) {

            $rows = $answers->where('question_id', $questionId);

            $title = $question->translations
                ->firstWhere('locale', $locale)?->title
                ?? $question->title;

            $responses = [];

            foreach ($rows as $answer) {

                $value = AnswerSnapshotBuilder::decodeStoredMachineValue($answer->value);

                if (is_array($value)) {
                    // checkbox → flatten
                    foreach ($value as $v) {
                        $responses[] = $v;
                    }
                } else {
                    if ($value !== null && $value !== '') {
                        $responses[] = $value;
                    }
                }
            }

            $result[] = [
                'question_id' => $questionId,
                'question_title' => $title,
                'type' => $question->type,
                'responses' => $responses,
            ];
        }

        return $result;
    }
}
