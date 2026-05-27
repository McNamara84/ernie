<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use Illuminate\Support\Facades\DB;

class LandingPageAnalyticsService
{
    public function recordPageView(LandingPage $landingPage): void
    {
        $this->incrementDailyCounter($landingPage, 'page_view_count');
    }

    public function recordFileDownloadClick(LandingPage $landingPage): void
    {
        $this->incrementDailyCounter($landingPage, 'file_download_click_count');
    }

    private function incrementDailyCounter(LandingPage $landingPage, string $column): void
    {
        $timestamp = now();
        $statisticDate = $timestamp->toDateString();
        $incrementExpression = $this->incrementExpression($column);

        LandingPageDailyStatistic::query()->insertOrIgnore([
            'landing_page_id' => $landingPage->getKey(),
            'statistic_date' => $statisticDate,
            'page_view_count' => 0,
            'file_download_click_count' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        LandingPageDailyStatistic::query()
            ->where('landing_page_id', $landingPage->getKey())
            ->where('statistic_date', $statisticDate)
            ->update([
                $column => DB::raw($incrementExpression),
                'updated_at' => $timestamp,
            ]);
    }

    /**
     * @return literal-string
     */
    private function incrementExpression(string $column): string
    {
        return match ($column) {
            'page_view_count' => 'page_view_count + 1',
            'file_download_click_count' => 'file_download_click_count + 1',
            default => throw new \InvalidArgumentException('Unsupported analytics counter column.'),
        };
    }
}