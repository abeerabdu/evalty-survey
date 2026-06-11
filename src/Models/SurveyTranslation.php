<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyTranslation extends Model
{
    protected $fillable = ['locale', 'title', 'description', 'welcome_message', 'completed_message', 'closed_message', 'limit_message'];
}
