<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An anonymous, hourly aggregate of signed-out public visitors and collection coverage.
 *
 * @property int $id
 * @property Carbon $bucket_started_at
 * @property int $landing_page_unique_visitor_count
 * @property int $portal_unique_visitor_count
 * @property int $combined_unique_visitor_count
 * @property int $observed_minute_count
 * @property Carbon|null $last_observed_minute_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'bucket_started_at',
    'landing_page_unique_visitor_count',
    'portal_unique_visitor_count',
    'combined_unique_visitor_count',
    'observed_minute_count',
    'last_observed_minute_at',
])]
final class PublicTrafficHourlyStatistic extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'bucket_started_at' => 'datetime',
        'landing_page_unique_visitor_count' => 'integer',
        'portal_unique_visitor_count' => 'integer',
        'combined_unique_visitor_count' => 'integer',
        'observed_minute_count' => 'integer',
        'last_observed_minute_at' => 'datetime',
    ];
}
