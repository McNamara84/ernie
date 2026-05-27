<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\PortalSearchDailyStatistic;
use App\Services\BotProtection\BotClassifierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalSearchAnalyticsService
{
    public function __construct(
        private readonly BotClassifierService $botClassifier,
    ) {}

    public function recordSearch(Request $request, ?string $term): void
    {
        $normalizedTerm = $this->normalizeTerm($term);

        if ($normalizedTerm === '') {
            return;
        }

        if ((bool) config('bot_protection.enabled', true) && $this->botClassifier->isKnownAiBot($request)) {
            return;
        }

        $timestamp = now();
        $statisticDate = $timestamp->toDateString();

        PortalSearchDailyStatistic::query()->insertOrIgnore([
            'statistic_date' => $statisticDate,
            'normalized_term' => $normalizedTerm,
            'search_count' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        PortalSearchDailyStatistic::query()
            ->where('statistic_date', $statisticDate)
            ->where('normalized_term', $normalizedTerm)
            ->update([
                'search_count' => DB::raw('search_count + 1'),
                'updated_at' => $timestamp,
            ]);
    }

    public function normalizeTerm(?string $term): string
    {
        if (! is_string($term)) {
            return '';
        }

        $trimmed = trim($term);

        if ($trimmed === '') {
            return '';
        }

        $collapsedWhitespace = preg_replace('/\s+/u', ' ', $trimmed);

        return mb_strtolower(is_string($collapsedWhitespace) ? $collapsedWhitespace : $trimmed);
    }
}