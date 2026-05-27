<?php

declare(strict_types=1);

use App\Http\Controllers\PortalSearchAnalyticsController;
use App\Models\PortalSearchDailyStatistic;

covers(PortalSearchAnalyticsController::class);

uses()->group('portal', 'statistics');

function portalSearchCountForToday(string $normalizedTerm): ?int
{
    return PortalSearchDailyStatistic::query()
        ->whereDate('statistic_date', now()->toDateString())
        ->where('normalized_term', $normalizedTerm)
        ->value('search_count');
}

describe('portal search analytics', function () {
    test('stores normalized search terms as daily aggregates', function () {
        $this->post('/portal/search-analytics', ['search_term' => '  Test   Query  '])
            ->assertNoContent();

        expect(portalSearchCountForToday('test query'))->toBe(1);
    });

    test('increments repeated normalized search terms', function () {
        $this->post('/portal/search-analytics', ['search_term' => 'Climate'])
            ->assertNoContent();

        $this->post('/portal/search-analytics', ['search_term' => ' climate '])
            ->assertNoContent();

        expect(portalSearchCountForToday('climate'))->toBe(2);
    });

    test('ignores blank search terms', function () {
        $this->post('/portal/search-analytics', ['search_term' => '   '])
            ->assertNoContent();

        expect(PortalSearchDailyStatistic::query()->count())->toBe(0);
    });

    test('does not count known ai bots', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.ai_user_agents' => ['GPTBot'],
        ]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.60',
            'HTTP_USER_AGENT' => 'GPTBot',
        ])->post('/portal/search-analytics', ['search_term' => 'seismic'])
            ->assertNoContent();

        expect(PortalSearchDailyStatistic::query()->count())->toBe(0);
    });
});