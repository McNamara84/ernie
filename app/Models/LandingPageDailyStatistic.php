<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily aggregate counters for published landing page analytics.
 *
 * @property int $id
 * @property int $landing_page_id
 * @property \Illuminate\Support\Carbon $statistic_date
 * @property int $page_view_count
 * @property int $file_download_click_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LandingPage $landingPage
 */
#[Fillable(['landing_page_id', 'statistic_date', 'page_view_count', 'file_download_click_count'])]
class LandingPageDailyStatistic extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'statistic_date' => 'date',
        'page_view_count' => 'integer',
        'file_download_click_count' => 'integer',
    ];

    /**
     * @return BelongsTo<LandingPage, static>
     */
    public function landingPage(): BelongsTo
    {
        /** @var BelongsTo<LandingPage, static> $relation */
        $relation = $this->belongsTo(LandingPage::class);

        return $relation;
    }
}