<?php

declare(strict_types=1);

use App\Exceptions\DataCiteRequestDeferredException;
use App\Http\Middleware\PrioritizeInteractiveApiRequests;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\InteractiveApiRequestContextService;
use App\Services\OrcidRequestLimiterService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class InteractiveDataCiteClock extends DataCiteRequestLimiter
{
    public int $milliseconds = 1_000_000;

    public ?Closure $duringWait = null;

    protected function nowMs(): int
    {
        return $this->milliseconds;
    }

    protected function pause(int $milliseconds): void
    {
        if ($this->duringWait !== null) {
            ($this->duringWait)();
        }
        $this->milliseconds += $milliseconds;
    }
}

class InteractiveOrcidClock extends OrcidRequestLimiterService
{
    public int $milliseconds = 1_000_000;

    protected function nowMs(): int
    {
        return $this->milliseconds;
    }

    protected function pause(int $milliseconds): void
    {
        $lock = Cache::lock('orcid_api_last_call_ms:lock', 5);
        expect($lock->get())->toBeTrue('ORCID must release its lock before waiting.');
        $lock->release();
        $this->milliseconds += $milliseconds;
    }
}

beforeEach(function () {
    Cache::flush();
    config([
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 10,
        'datacite.landing_page_url_update.window_seconds' => 300,
        'datacite.interactive_reserved_requests' => 1,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST',
        'datacite.test.password' => 'test-only',
    ]);
    $this->priority = app(InteractiveApiRequestContextService::class);
});

it('restores interactive priority after nested calls and exceptions', function () {
    expect($this->priority->isInteractive())->toBeFalse();
    expect(fn () => $this->priority->run(function () {
        $this->priority->run(fn () => expect($this->priority->isInteractive())->toBeTrue());
        expect($this->priority->isInteractive())->toBeTrue();
        throw new RuntimeException('interrupted request');
    }))->toThrow(RuntimeException::class, 'interrupted request');
    expect($this->priority->isInteractive())->toBeFalse();
});

it('applies request priority only for the lifetime of the middleware', function () {
    $middleware = app(PrioritizeInteractiveApiRequests::class);
    $response = $middleware->handle(Request::create('/resources/1/register-doi', 'POST'), function () {
        expect($this->priority->isInteractive())->toBeTrue();

        return response('saved');
    });
    expect($response->getContent())->toBe('saved')
        ->and($this->priority->isInteractive())->toBeFalse();
});

it('does not start another external call after the interactive operation budget expires', function () {
    $context = new InteractiveApiRequestContextService(0);
    app()->instance(InteractiveApiRequestContextService::class, $context);
    Http::fake();
    $client = new DataCiteMemberApiClient(new InteractiveDataCiteClock, true);
    expect(fn () => $context->run(fn () => $client->getDoi('10.5880/budget-expired')))
        ->toThrow(DataCiteRequestDeferredException::class);
    Http::assertNothingSent();
    expect($context->remainingMilliseconds())->toBe(PHP_INT_MAX);
});

it('limits transient retries for an interactive update', function () {
    Http::fakeSequence()->push([], 503)->push([], 503)->push([], 200);
    $client = new DataCiteMemberApiClient(new InteractiveDataCiteClock, true);
    expect($this->priority->run(fn () => $client->updateDoi('10.5880/retries', ['data' => ['attributes' => []]]))->status())->toBe(503);
    Http::assertSentCount(2);
});

it('reserves editor capacity without exceeding the shared DataCite quota', function () {
    $limiter = new InteractiveDataCiteClock;
    for ($index = 0; $index < 9; $index++) {
        expect($limiter->reserveSlot())->toBe(0);
    }
    expect($limiter->reserveSlot())->toBe(300000);
    expect($this->priority->run(fn () => $limiter->reserveSlot()))->toBe(0);
    expect($this->priority->run(fn () => $limiter->reserveSlot()))->toBe(300000);
    $limiter->milliseconds += 300000;
    expect($limiter->reserveSlot())->toBe(0);
});

it('lets a waiting editor take the next slot ahead of a background caller', function () {
    config(['datacite.landing_page_url_update.minimum_interval_ms' => 1000]);
    $limiter = new InteractiveDataCiteClock;
    expect($limiter->reserveSlot())->toBe(0);
    expect($this->priority->run(fn () => $limiter->reserveSlot()))->toBe(1000);
    $limiter->milliseconds += 1000;
    expect($limiter->reserveSlot())->toBeGreaterThan(0);
    expect($this->priority->run(fn () => $limiter->reserveSlot()))->toBe(0);
});

it('bounds interactive waits and preserves real provider cooldowns', function () {
    $limiter = new InteractiveDataCiteClock;
    $limiter->imposeCooldown(60);
    expect(fn () => $this->priority->run(fn () => $limiter->waitForSlot()))
        ->toThrow(DataCiteRequestDeferredException::class);
    expect($limiter->milliseconds)->toBe(1_000_000);
    $limiter->clear();
    expect($limiter->reserveSlot())->toBe(0);
});

it('never holds the DOI lock while waiting for capacity, including retries', function () {
    config(['datacite.landing_page_url_update.minimum_interval_ms' => 1000]);
    $limiter = new InteractiveDataCiteClock;
    $limiter->reserveSlot();
    $waits = 0;
    $limiter->duringWait = function () use (&$waits) {
        $key = 'datacite:doi-write:'.hash('sha256', 'test:10.5880/interactive');
        $lock = Cache::lock($key, 5);
        expect($lock->get())->toBeTrue('Quota waits must not lock out another DOI writer.');
        $lock->release();
        $waits++;
    };
    Http::fakeSequence()->push([], 503)->push(['data' => ['id' => '10.5880/interactive']], 200);
    $client = new DataCiteMemberApiClient($limiter, true);
    $response = $this->priority->run(fn () => $client->updateDoi('10.5880/interactive', ['data' => ['attributes' => ['titles' => []]]]));
    expect($response->successful())->toBeTrue()->and($waits)->toBeGreaterThanOrEqual(2);
    Http::assertSentCount(2);
});

it('yields ORCID background searches to interactive verification without holding a lock', function () {
    config(['services.orcid.rate_limit_delay_ms' => 2100]);
    $limiter = new InteractiveOrcidClock;
    expect($limiter->reserveSlot())->toBe(0);
    expect($this->priority->run(fn () => $limiter->reserveSlot()))->toBe(2100);
    $limiter->milliseconds += 2100;
    expect($limiter->reserveSlot())->toBeGreaterThan(0);
    $this->priority->run(fn () => $limiter->waitForSlot());
    expect($this->priority->isInteractive())->toBeFalse();
    $limiter->waitForSlot();
    expect($limiter->milliseconds)->toBeGreaterThanOrEqual(1_004_200);
});

it('refuses unbounded ORCID waits', function () {
    config(['services.orcid.rate_limit_delay_ms' => 6000]);
    $limiter = new InteractiveOrcidClock;
    $limiter->reserveSlot();
    expect(fn () => $this->priority->run(fn () => $limiter->waitForSlot()))
        ->toThrow(RuntimeException::class, 'ORCID verification service is busy');
});

it('does not treat an ORCID lock timeout as permission to bypass the limit', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);
    expect((new InteractiveOrcidClock)->reserveSlot())->toBe(250);
});
