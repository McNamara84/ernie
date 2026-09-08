<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A minute-level resource sample collected from the host VM.
 *
 * @property int $id
 * @property Carbon $recorded_at
 * @property float|null $cpu_usage_percent
 * @property float $memory_usage_percent
 * @property int $memory_used_bytes
 * @property int $memory_total_bytes
 * @property int $cpu_total_ticks
 * @property int $cpu_idle_ticks
 */
#[Fillable([
    'recorded_at',
    'cpu_usage_percent',
    'memory_usage_percent',
    'memory_used_bytes',
    'memory_total_bytes',
    'cpu_total_ticks',
    'cpu_idle_ticks',
])]
class SystemMetricSample extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'recorded_at' => 'datetime',
        'cpu_usage_percent' => 'float',
        'memory_usage_percent' => 'float',
        'memory_used_bytes' => 'integer',
        'memory_total_bytes' => 'integer',
        'cpu_total_ticks' => 'integer',
        'cpu_idle_ticks' => 'integer',
    ];
}
