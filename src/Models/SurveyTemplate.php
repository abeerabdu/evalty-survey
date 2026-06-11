<?php

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SurveyTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'name',
        'description',
        'config',
        'icon',
        'preview_image',
        'is_active',
        'version'
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function surveys()
    {
        return $this->hasMany(Survey::class);
    }
}
