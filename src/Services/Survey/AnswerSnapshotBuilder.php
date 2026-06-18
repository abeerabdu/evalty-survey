<?php

namespace Evalty\Survey\Services\Survey;

use Evalty\Survey\Models\Answer;
use Evalty\Survey\Models\Question;
use Evalty\Survey\Models\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Single writer for survey answer persistence (machine `value` + human `label_snapshot`).
 *
 * answers.value — PHP shapes before encode / after decode (each is one JSON document at rest):
 * - string → JSON string (radio/select internal key, text, textarea)
 * - int → JSON number (rating machine value)
 * - array<string> → JSON array of strings (checkbox internal keys)
 *
 * label_snapshot — PHP shapes before encode:
 * - string, or array of strings (checkbox labels), always one JSON document at rest.
 *
 * Runtime submissions MUST use this service; do not persist answers from controllers directly.
 */
class AnswerSnapshotBuilder
{
    public static function readCanonicalJsonEnabled(): bool
    {
        return (bool) config('survey.read_canonical_json', true);
    }

    public static function writeCanonicalJsonEnabled(): bool
    {
        return (bool) config('survey.write_canonical_json', true);
    }

    /**
     * Decode a stored answers.value column for display / analytics (dual-read for legacy rows).
     *
     * @return string|int|array<int, string>|null
     */
    public static function decodeStoredMachineValue(?string $stored): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $trimmed = trim($stored);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return self::validateDecodedMachineShape($decoded) ? $decoded : $trimmed;
        }

        return $trimmed;
    }

    /**
     * @param  mixed  $decoded  Output of json_decode(..., true) or already-PHP value from casts
     */
    public static function validateDecodedMachineShape(mixed $decoded): bool
    {
        if (is_int($decoded) || is_float($decoded)) {
            return true;
        }
        if (is_string($decoded)) {
            return true;
        }
        if (! is_array($decoded)) {
            return false;
        }
        foreach ($decoded as $k => $v) {
            if (! is_int($k) || ! is_string($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{question_id:int|string, value:mixed}>  $answersInput
     * @param  array<int, Question>  $questionsById
     */
    // public function persistSubmissionAnswers(
    //     Response $response,
    //     array $answersInput,
    //     array $questionsById,
    //     string $submittedLocale
    // ): void {
    //     foreach ($answersInput as $row) {
    //         $qid = (int) ($row['question_id'] ?? 0);
    //         $question = $questionsById[$qid] ?? null;
    //         if (! $question) {
    //             throw ValidationException::withMessages([
    //                 'answers' => ['Invalid question for this survey.'],
    //             ]);
    //         }

    //         $pair = $this->buildMachineAndLabel($question, $row['value'] ?? null, $submittedLocale);

    //         $this->insertAnswer($response, $question->id, $pair['machine'], $pair['label']);
    //     }
    // }

    public function persistSubmissionAnswers(
        Response $response,
        array $answersInput,
        array $questionsById,
        string $submittedLocale
    ): void {
        foreach ($answersInput as $row) {
            $qid = (int) ($row['question_id'] ?? 0);
            $question = $questionsById[$qid] ?? null;
            if (! $question) {
                throw ValidationException::withMessages([
                    'answers' => ['Invalid question for this survey.'],
                ]);
            }

            $pair = $this->buildMachineAndLabel($question, $row['value'] ?? null, $submittedLocale);

            $this->insertAnswer($response, $question->id, $pair['machine'], $pair['label']);
        }
    }

    /**
     * @return array{machine: string|int|array<int, string>, label: string|array<int, string>}
     */
    public function buildMachineAndLabel(Question $question, mixed $raw, string $locale): array
    {
        $question->loadMissing('options.translations');

        if (! self::writeCanonicalJsonEnabled()) {
            return $this->legacyShape($question, $raw);
        }

        return match ($question->type) {
            'text', 'textarea' => $this->buildText($raw),
            'radio', 'select' => $this->buildSingleChoice($question, $raw, $locale),
            'checkbox' => $this->buildCheckbox($question, $raw, $locale),
            'rating' => $this->buildRating($raw),
            default => throw ValidationException::withMessages([
                'answers' => ['Unsupported question type: ' . $question->type],
            ]),
        };
    }

    /**
     * @return array{machine: string|int|array<int, string>, label: string|array<int, string>}
     */
    protected function legacyShape(Question $question, mixed $raw): array
    {
        if ($question->type === 'checkbox') {
            $keys = $this->normalizeCheckboxKeys($raw);

            return ['machine' => $keys, 'label' => $keys];
        }

        if (is_array($raw)) {
            $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE);

            return ['machine' => $encoded !== false ? $encoded : '', 'label' => $encoded !== false ? $encoded : ''];
        }

        $scalar = $raw === null ? '' : (is_scalar($raw) ? (string) $raw : '');

        return ['machine' => $scalar, 'label' => $scalar];
    }

    /**
     * @return array{machine: string, label: string}
     */
    protected function buildText(mixed $raw): array
    {
        if (is_array($raw) || is_object($raw)) {
            throw ValidationException::withMessages(['answers' => ['Invalid text answer.']]);
        }
        $text = $raw === null || $raw === '' ? '' : (string) $raw;

        return ['machine' => $text, 'label' => $text];
    }

    /**
     * @return array{machine: string, label: string}
     */
    protected function buildSingleChoice(Question $question, mixed $raw, string $locale): array
    {
        if (is_array($raw) || is_object($raw)) {
            throw ValidationException::withMessages(['answers' => ['Invalid choice answer.']]);
        }
        $key = $raw === null || $raw === '' ? '' : (string) $raw;
        $option = $question->options->firstWhere('value', $key);
        if (! $option && is_numeric($key)) {
            $option = $question->options->firstWhere('id', (int) $key);
        }

        if (! $option) {
            throw ValidationException::withMessages(['answers' => ['Invalid option selected.']]);
        }

        $machine = (string) ($option->value ?? (string) $option->id);
        $label = (string) ($option->getTranslation('label', $locale) ?? '');

        return ['machine' => $machine, 'label' => $label];
    }

    /**
     * @return array{machine: array<int, string>, label: array<int, string>}
     */
    protected function buildCheckbox(Question $question, mixed $raw, string $locale): array
    {
        $keys = $this->normalizeCheckboxKeys($raw);
        $machine = [];
        $labels = [];

        foreach ($keys as $key) {
            $option = $question->options->firstWhere('value', $key);
            if (! $option && is_numeric($key)) {
                $option = $question->options->firstWhere('id', (int) $key);
            }

            if (! $option) {
                throw ValidationException::withMessages(['answers' => ['Invalid checkbox option.']]);
            }

            $mk = (string) ($option->value ?? (string) $option->id);
            $machine[] = $mk;
            $labels[] = (string) ($option->getTranslation('label', $locale) ?? '');
        }

        if (count($machine) !== count($labels)) {
            Log::warning('survey.checkbox_parity_mismatch', [
                'question_id' => $question->id,
                'machine_count' => count($machine),
                'label_count' => count($labels),
            ]);
            throw ValidationException::withMessages(['answers' => ['Checkbox answer is inconsistent.']]);
        }

        return ['machine' => $machine, 'label' => $labels];
    }

    /**
     * @return array{machine: int, label: string}
     */
    protected function buildRating(mixed $raw): array
    {
        if (is_array($raw) || (is_string($raw) && $raw !== '' && ! is_numeric($raw))) {
            throw ValidationException::withMessages(['answers' => ['Invalid rating answer.']]);
        }
        if ($raw === null || $raw === '') {
            $int = 0;
        } else {
            $int = (int) round((float) $raw);
        }

        return ['machine' => $int, 'label' => (string) $int];
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeCheckboxKeys(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                return [$raw];
            }
        }
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['answers' => ['Invalid checkbox payload.']]);
        }

        $keys = [];
        foreach ($raw as $v) {
            if (! is_scalar($v)) {
                throw ValidationException::withMessages(['answers' => ['Invalid checkbox payload.']]);
            }
            $keys[] = (string) $v;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  string|int|array<int, string>  $machine
     * @param  string|array<int, string>  $label
     */
    protected function insertAnswer(Response $response, int $questionId, mixed $machine, mixed $label): void
    {
        try {
            $valueJson = json_encode($machine, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $labelJson = json_encode($label, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw ValidationException::withMessages(['answers' => ['Could not encode answer.']]);
        }

        if (! self::validateDecodedMachineShape(json_decode($valueJson, true))) {
            throw ValidationException::withMessages(['answers' => ['Invalid machine answer shape.']]);
        }

        Answer::query()->create([
            'response_id' => $response->id,
            'question_id' => $questionId,
            'value' => $valueJson,
            'label_snapshot' => $labelJson,
            'schema_version' => 1,
        ]);
    }
}
