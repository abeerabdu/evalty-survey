<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;

class OptionTranslation extends Model
{
    protected $fillable = ['locale', 'label'];
}
