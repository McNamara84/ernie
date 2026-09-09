<?php

declare(strict_types=1);

use App\Enums\PublicTrafficSurface;
use App\Models\PublicTrafficHourlyStatistic;
use App\Models\User;
use App\Services\PublicTraffic\PublicTrafficAggregateStoreService;
use App\Services\PublicTraffic\PublicTrafficRecorderService;
use App\Services\PublicTraffic\PublicTrafficWarningLoggerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

covers(
    PublicTrafficSurface::class,
    PublicTrafficHourlyStatistic::class,
    PublicTrafficAggregateStoreService::class,
    PublicTrafficRecorderService::class,
    PublicTrafficWarningLoggerService::class,
);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-09 12:15:00 UTC');
    Cache::flush();
    config()->set([
        'app.key' => 'base64:test-public-traffic-key',
        'public_traffic.enabled' => true,
        'public_traffic.deduplication_grace_seconds' => 300,
        'bot_protection.enabled' => true,
        'bot_protection.ai_user_agents' => ['GPTBot'],
        'bot_protection.crawler_user_agents' => ['Googlebot', 'facebookexternalhit'],
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function publicTrafficRequest(
    string $ip = '203.0.113.10',
    string $userAgent = 'Mozilla/5.0 Test Browser',
    ?User $user = null,
): Request {
    $request = Request::create('/doi-search', 'GET', server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => $userAgent,
    ]);
    $request->setUserResolver(static fn (): ?User => $user);

    return $request;
}

function publicTrafficRecorder(): PublicTrafficRecorderService
{
    return app(PublicTrafficRecorderService::class);
}

it('records one surface and one combined visitor for a normal signed-out browser', function (): void {
    publicTrafficRecorder()->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);

    $row = PublicTrafficHourlyStatistic::query()->sole();

    expect($row->bucket_started_at->toIso8601String())->toBe('2026-09-09T12:00:00+00:00')
        ->and($row->portal_unique_visitor_count)->toBe(1)
        ->and($row->landing_page_unique_visitor_count)->toBe(0)
        ->and($row->combined_unique_visitor_count)->toBe(1)
        ->and($row->observed_minute_count)->toBe(0)
        ->and($row->last_observed_minute_at)->toBeNull();
});

it('deduplicates repeated requests per surface and across surfaces in the same hour', function (): void {
    $recorder = publicTrafficRecorder();
    $request = publicTrafficRequest();

    $recorder->record($request, PublicTrafficSurface::PORTAL);
    $recorder->record($request, PublicTrafficSurface::PORTAL);
    $recorder->record($request, PublicTrafficSurface::LANDING_PAGE);
    $recorder->record($request, PublicTrafficSurface::LANDING_PAGE);

    $row = PublicTrafficHourlyStatistic::query()->sole();

    expect($row->portal_unique_visitor_count)->toBe(1)
        ->and($row->landing_page_unique_visitor_count)->toBe(1)
        ->and($row->combined_unique_visitor_count)->toBe(1);
});

it('counts different visitors and counts the same visitor again in a later hour', function (): void {
    $recorder = publicTrafficRecorder();

    $recorder->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);
    $recorder->record(publicTrafficRequest(ip: '203.0.113.11'), PublicTrafficSurface::PORTAL);

    CarbonImmutable::setTestNow('2026-09-09 13:00:00 UTC');
    $recorder->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);

    $rows = PublicTrafficHourlyStatistic::query()->oldest('bucket_started_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->portal_unique_visitor_count)->toBe(2)
        ->and($rows[0]->combined_unique_visitor_count)->toBe(2)
        ->and($rows[1]->portal_unique_visitor_count)->toBe(1)
        ->and($rows[1]->combined_unique_visitor_count)->toBe(1);
});

it('excludes authenticated users, empty user agents, and configured bots', function (): void {
    $recorder = publicTrafficRecorder();

    $recorder->record(publicTrafficRequest(user: User::factory()->admin()->create()), PublicTrafficSurface::PORTAL);
    $recorder->record(publicTrafficRequest(userAgent: ''), PublicTrafficSurface::PORTAL);
    $recorder->record(publicTrafficRequest(userAgent: 'Googlebot/2.1'), PublicTrafficSurface::PORTAL);
    $recorder->record(publicTrafficRequest(userAgent: 'GPTBot/1.0'), PublicTrafficSurface::LANDING_PAGE);

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
});

it('excludes authenticated users independently of their role', function (string $role): void {
    publicTrafficRecorder()->record(
        publicTrafficRequest(user: User::factory()->create(['role' => $role])),
        PublicTrafficSurface::PORTAL,
    );

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
})->with(['admin', 'group_leader', 'curator', 'beginner']);

it('does nothing when public traffic analytics are disabled', function (): void {
    config()->set('public_traffic.enabled', false);

    publicTrafficRecorder()->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
});

it('maps every traffic surface to its aggregate column', function (): void {
    expect(PublicTrafficSurface::LANDING_PAGE->counterColumn())->toBe('landing_page_unique_visitor_count')
        ->and(PublicTrafficSurface::PORTAL->counterColumn())->toBe('portal_unique_visitor_count');
});

it('uses opaque bucket-bound keys that expire shortly after the hour', function (): void {
    $observedKeys = [];
    $observedExpirations = [];

    Cache::shouldReceive('add')
        ->twice()
        ->andReturnUsing(function (string $key, bool $value, CarbonImmutable $expiresAt) use (
            &$observedKeys,
            &$observedExpirations,
        ): bool {
            $observedKeys[] = $key;
            $observedExpirations[] = $expiresAt->toIso8601String();

            return $value;
        });

    publicTrafficRecorder()->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);

    expect($observedKeys)->toHaveCount(2)
        ->and($observedKeys[0])->toMatch('/^public-traffic:visitor:2026090912:portal:[a-f0-9]{64}$/')
        ->and($observedKeys[1])->toMatch('/^public-traffic:visitor:2026090912:combined:[a-f0-9]{64}$/')
        ->and(implode('|', $observedKeys))->not->toContain('203.0.113.10', 'Mozilla')
        ->and(array_unique($observedExpirations))->toBe(['2026-09-09T13:05:00+00:00']);
});

it('fails safely and logs no visitor data when fingerprinting cannot be secured', function (): void {
    Log::spy();
    config()->set('app.key', '');

    publicTrafficRecorder()->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);
    publicTrafficRecorder()->record(publicTrafficRequest(ip: '203.0.113.11'), PublicTrafficSurface::PORTAL);

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            'Failed to record anonymous public traffic.',
            Mockery::on(static function (array $context): bool {
                $encoded = json_encode($context);

                return $context === ['exception_class' => RuntimeException::class]
                    && is_string($encoded)
                    && ! str_contains($encoded, '203.0.113.10')
                    && ! str_contains($encoded, '203.0.113.11')
                    && ! str_contains($encoded, 'Mozilla');
            }),
        );
});

it('removes deduplication keys after a database failure so a later retry can count', function (): void {
    Log::spy();
    $defaultConnection = (string) config('database.default');
    config()->set([
        'database.default' => 'public-traffic-broken',
        'database.connections.public-traffic-broken' => ['driver' => 'unsupported'],
    ]);

    try {
        publicTrafficRecorder()->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);
    } finally {
        config()->set('database.default', $defaultConnection);
    }

    publicTrafficRecorder()->record(publicTrafficRequest(), PublicTrafficSurface::PORTAL);

    $row = PublicTrafficHourlyStatistic::query()->sole();
    expect($row->portal_unique_visitor_count)->toBe(1)
        ->and($row->combined_unique_visitor_count)->toBe(1);
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Failed to record anonymous public traffic.', Mockery::type('array'));
});
