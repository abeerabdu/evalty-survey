<?php

namespace Evalty\Survey\Enums;

enum SurveyState: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case NOT_PUBLIC = 'not_public';
    case NOT_FOUND = 'not_found';
    case NOT_OPEN_YET = 'not_open_yet';
}
