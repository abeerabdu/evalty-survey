<?php

declare(strict_types=1);

namespace Evalty\Survey\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $snapshot_key
 * @property string $status
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon|null $generated_at
 * @property int|null $tenant_count
 * @property int $failed_tenant_count
 * @property array<int, string>|null $failed_tenant_sample
 */
final class CentralKpiSnapshot extends Model
{
    protected $fillable = [
        'snapshot_key',
        'status',
        'payload',
        'generated_at',
        'tenant_count',
        'failed_tenant_count',
        'failed_tenant_sample',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generated_at' => 'datetime',
            'failed_tenant_sample' => 'array',
        ];
    }
}
