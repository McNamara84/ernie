<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily aggregate counters for normalized portal search terms.
 *
 * @property int $id
 * @property Carbon $statistic_date
 * @property string $normalized_term
 * @property int $search_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['statistic_date', 'normalized_term', 'search_count'])]
class PortalSearchDailyStatistic extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'statistic_date' => 'date',
        'search_count' => 'integer',
    ];
}
