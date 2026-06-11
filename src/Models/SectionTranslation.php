<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;

class SectionTranslation extends Model
{
    protected $fillable = ['locale', 'title'];
}
