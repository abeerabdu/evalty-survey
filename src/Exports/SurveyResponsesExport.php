<?php

namespace Evalty\Survey\Exports;

use Evalty\Survey\Models\Response;
use Evalty\Survey\Models\Survey;
use Evalty\Survey\Services\Survey\AnswerSnapshotBuilder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\{
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize
};

class SurveyResponsesExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithEvents
{
    protected int|string $surveyId;
    protected $questions;

    public function __construct(int|string $surveyId)
    {
        $this->surveyId = $surveyId;

        // ✅ CORRECT SOURCE: Survey → Questions (ordered)
        $survey = Survey::with([
            'sections.questions.translations',
            'sections.questions.options',
            'directQuestions.translations',
            'directQuestions.options.translations',
        ])->findOrFail($surveyId);

        $this->questions = $survey->sections
            ->sortBy('order')
            ->flatMap(
                fn($section) =>
                $section->questions->sortBy('order')
            )
            ->merge($survey->directQuestions)
            ->filter(fn($q) => $q !== null)
            ->values();
    }

    public function collection()
    {
        return Response::with('answers.question')
            ->where('survey_id', $this->surveyId)
            ->get();
    }

    public function map($response): array
    {
        $row = [
            $response->id,
            $response->created_at?->format('Y-m-d H:i'),
            $response->submitted_at?->format('Y-m-d H:i'),
            $response->email ?? 'anonymous',
            $response->name ?? '',
            $response->submitted_locale ?? '',
        ];

        // ✅ KEY BY question_id (VERY IMPORTANT)
        $answersMap = [];

        foreach ($response->answers as $answer) {

            $qid = $answer->question_id;

            $question = $answer->question;

            $label = null;

            // 1️⃣ checkbox / array / JSON value
            $decoded = AnswerSnapshotBuilder::decodeStoredMachineValue($answer->value);

            // 2️⃣ if it's real text/value → use it
            if (is_string($decoded) && $decoded !== '') {
                $label = $decoded;
            }

            // 3️⃣ fallback label snapshot ONLY if needed
            if (!$label) {
                $label = json_decode($answer->label_snapshot, true);

                if (is_array($label)) {
                    $label = implode(', ', $label);
                }
            }

            $answersMap[$qid] = $label ?? '';
        }

        // ✅ LOOP ALL QUESTIONS (guaranteed order)
        foreach ($this->questions as $question) {
            $row[] = $answersMap[$question->id] ?? '';
        }

        return $row;
    }

    public function headings(): array
    {
        return array_merge(
            [
                'ID',
                'Start time',
                'Completion time',
                'Email',
                'Name',
                'Submitted Locale',
            ],

            // ✅ Get REAL question titles
            $this->questions->map(
                fn($q) =>
                $this->getQuestionTitle($q, $q->survey?->default_lang)
            )->toArray()
        );
    }

    private function getQuestionTitle($question, $locale): string
    {
        // 1. try submitted locale
        $title = $question->translations
            ->firstWhere('locale', $locale)?->title;

        // 2. fallback survey default
        if (!$title) {
            $title = $question->translations
                ->firstWhere('locale', $question->survey?->default_lang)?->title;
        }

        // 3. fallback any translation
        if (!$title) {
            $title = $question->translations->first()?->title;
        }

        return $title ?? 'Untitled';
    }
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [ // header row
                'font' => [
                    'bold' => true,
                    'size' => 12,
                ],
            ],
        ];
    }
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {

                $sheet = $event->sheet->getDelegate();

                // 🔥 Auto filter
                $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

                // 🔥 Freeze first row
                $sheet->freezePane('A2');

                // 🔥 Center align
                $sheet->getStyle($sheet->calculateWorksheetDimension())
                    ->getAlignment()
                    ->setVertical('center');

                // 🔥 Wrap text for long answers
                $sheet->getStyle($sheet->calculateWorksheetDimension())
                    ->getAlignment()
                    ->setWrapText(true);
            },
        ];
    }
}
