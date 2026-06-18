<?php

namespace Evalty\Survey\Services\Survey;

use Evalty\Survey\Models\Survey;
use Evalty\Survey\Enums\SurveyState;

class SurveyAccessService
{
    public function check(?Survey $survey): array
    {
        if (!$survey) {
            return [
                'state' => SurveyState::NOT_FOUND,
                'message' => __('survey.not_found')
            ];
        }

        if ($survey->access_type !== 'public') {
            return [
                'state' => SurveyState::NOT_PUBLIC,
                'message' => __('survey.not_public')
            ];
        }

        if ($survey->open_at && now()->lt($survey->open_at)) {
            return [
                'state' => SurveyState::NOT_OPEN_YET,
                'message' => __('survey.not_open_yet')
            ];
        }

        if ($survey->close_at && now()->gt($survey->close_at)) {
            return [
                'state' => SurveyState::CLOSED,
                'message' => $survey->getMessage('closed_message')
            ];
        }

        if ($survey->isClosed()) {
            return [
                'state' => SurveyState::CLOSED,
                'message' => $survey->getMessage('closed_message')
            ];
        }

        return [
            'state' => SurveyState::OPEN,
            'message' => null
        ];
    }
}
