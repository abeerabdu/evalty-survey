<?php

namespace Evalty\Survey\Services\Survey;

use Evalty\Survey\Models\SurveyTemplate;
use Evalty\Survey\Models\Survey;
use Exception;

class SurveyTemplateService
{
    public function getActiveTemplates()
    {
        return SurveyTemplate::where('is_active', true)->get();
    }
}
