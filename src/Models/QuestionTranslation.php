<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionTranslation extends Model
{
    protected $fillable = ['locale', 'title'];
}
