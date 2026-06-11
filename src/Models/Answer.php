<?php

namespace Evalty\Survey\Models;

use App\Services\Survey\AnswerSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $value JSON document string at rest (see AnswerSnapshotBuilder docblock).
 * @property string|null $label_snapshot JSON document string at rest.
 */
class Answer extends Model
{
    protected $fillable = [
        'response_id',
        'question_id',
        'value',
        'label_snapshot',
        'schema_version',
        // 'option_id',
        // 'value_text',
        // 'value_int',
    ];

    protected $casts = [
        'schema_version' => 'integer',
        'option_id' => 'integer',
        // 'value' => 'array', // checkbox / multi-select
    ];

    //      Relationships

    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
    public function option()
    {
        return $this->belongsTo(Option::class);
    }

    /**
     * Decoded machine payload (string | int | list of strings) for analytics / UI.
     */
    public function machineDocument(): mixed
    {
        $raw = $this->getRawOriginal('value') ?? $this->attributes['value'] ?? null;

        return AnswerSnapshotBuilder::decodeStoredMachineValue(
            is_string($raw) ? $raw : (is_scalar($raw) ? (string) $raw : null)
        );
    }

    public function setValueAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['value'] = json_encode($value);
        } elseif (is_bool($value)) {
            $this->attributes['value'] = $value ? '1' : '0';
        } else {
            $this->attributes['value'] = $value;
        }
    }
}
