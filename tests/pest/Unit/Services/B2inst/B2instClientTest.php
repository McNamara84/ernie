<?php

declare(strict_types=1);

use App\Services\B2inst\B2instClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\B2instFixture as Fixture;

covers(B2instClient::class);

beforeEach(function (): void {
    config(['b2inst.host' => 'https://b2inst.example.test', 'b2inst.page_size' => 1, 'b2inst.retry_delay' => 0]);
    Http::preventStrayRequests();
    Sleep::fake();
});

it('follows relative v3 pagination and verifies the first page', function (): void {
    $first = Fixture::page([Fixture::record()], 2, '?page=2&size=1&sort=newest&allversions=False');
    Http::fakeSequence()->push($first)->push(Fixture::page([Fixture::record('second')], 2))->push($first);
    expect(app(B2instClient::class)->records()['records'])->toHaveCount(2);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'allversions=false') && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-API-Key'));
});

it('accepts only the production alias group', function (): void {
    config(['b2inst.host' => 'https://b2inst.pid.gwdg.de']);
    $client = app(B2instClient::class);
    expect($client->isRegistryUrl('https://b2inst.gwdg.de/api/records'))->toBeTrue()
        ->and($client->isRegistryUrl('https://b2inst-test.gwdg.de/api/records'))->toBeFalse();
    $first = Fixture::page([Fixture::record()], 2, 'https://b2inst.gwdg.de/api/records?page=2&size=1&sort=newest&allversions=false');
    Http::fakeSequence()->push($first)->push(Fixture::page([Fixture::record('second')], 2))->push($first);
    expect($client->records()['total'])->toBe(2);
});

it('rejects unsafe or changed pagination scope before another request', function (string $next): void {
    Http::fakeSequence()->push(Fixture::page([Fixture::record()], 2, $next));
    expect(fn () => app(B2instClient::class)->records())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([
    'foreign origin' => 'https://evil.test/api/records?page=2&size=1&sort=newest&allversions=false',
    'http' => 'http://b2inst.example.test/api/records?page=2&size=1&sort=newest&allversions=false',
    'userinfo' => 'https://user@b2inst.example.test/api/records?page=2&size=1&sort=newest&allversions=false',
    'drafts' => '/api/user/records?page=2&size=1&sort=newest&allversions=false',
    'all versions' => '?page=2&size=1&sort=newest&allversions=true',
    'filter' => '?page=2&size=1&sort=newest&allversions=false&q=restricted',
    'sort' => '?page=2&size=1&sort=updated-desc&allversions=false',
    'loop' => '?page=1&size=1&sort=newest&allversions=false',
    'fragment' => '?page=2&size=1&sort=newest&allversions=false#x',
    'size' => '?page=2&size=100&sort=newest&allversions=false',
]);

it('validates the search contract instead of coercing missing totals', function (array $payload): void {
    Http::fakeSequence()->push($payload);
    expect(fn () => app(B2instClient::class)->count())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([
    [[]], [['hits' => ['hits' => []]]], [['hits' => ['hits' => [], 'total' => '0']]],
    [['hits' => ['hits' => [], 'total' => ['value' => 0]]]], [['hits' => ['hits' => [], 'total' => -1]]],
    [['hits' => ['hits' => 'bad', 'total' => 0]]], [['hits' => ['hits' => ['bad'], 'total' => 1]]],
]);

it('retries detected snapshot movement once and then fails incomplete imports', function (string $case): void {
    $first = Fixture::page([Fixture::record()], 2, '?page=2&size=1&sort=newest&allversions=false');
    $second = match ($case) {
        'duplicate' => Fixture::page([Fixture::record()], 2),
        'changed total' => Fixture::page([Fixture::record('second')], 3),
        'empty page' => Fixture::page([], 2),
        default => Fixture::page([Fixture::record()], 2),
    };
    if ($case === 'early end') {
        $first['links']['next'] = null;
        Http::fakeSequence()->push($first)->push($first);
    } else {
        Http::fakeSequence()->push($first)->push($second)->push($first)->push($second);
    }
    expect(fn () => app(B2instClient::class)->records())->toThrow(RuntimeException::class);
    Http::assertSentCount($case === 'early end' ? 2 : 4);
})->with(['duplicate', 'changed total', 'empty page', 'early end']);

it('recovers once from same-count revision changes at the search boundary', function (): void {
    $old = Fixture::page([Fixture::record()]);
    $new = Fixture::page([Fixture::record(overrides: ['revision_id' => 3])]);
    Http::fakeSequence()->push($old)->push($new)->push($new)->push($new);
    expect(app(B2instClient::class)->records()['records'][0]['revision_id'])->toBe(3);
    Http::assertSentCount(4);
});

it('retries transient errors at most three times', function (int $status): void {
    Http::fakeSequence()->push([], $status)->push([], $status)->push(Fixture::page([]));
    expect(app(B2instClient::class)->count())->toBe(0);
    Http::assertSentCount(3);
})->with([429, 500, 502, 503, 504]);

it('does not follow redirects or retry ordinary client errors', function (int $status): void {
    Http::fakeSequence()->push([], $status, ['Location' => 'https://evil.test']);
    expect(fn () => app(B2instClient::class)->count())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([301, 302, 307, 400, 401, 403, 404]);

it('bounds server Retry-After by the total time budget', function (): void {
    Http::fakeSequence()->push([], 429, ['Retry-After' => '1200']);
    expect(fn () => app(B2instClient::class)->count())->toThrow(RuntimeException::class, 'time budget');
    Http::assertSentCount(1);
});

it('honors Retry-After and never disables request timeouts', function (): void {
    config(['b2inst.timeout' => 0, 'b2inst.connect_timeout' => 0]);
    $options = [];
    $attempt = 0;
    Http::fake(function ($request, array $requestOptions) use (&$options, &$attempt) {
        $options = $requestOptions;

        return ++$attempt === 1 ? Http::response([], 429, ['Retry-After' => '2']) : Http::response(Fixture::page([]));
    });
    expect(app(B2instClient::class)->count())->toBe(0)
        ->and($options['timeout'])->toEqual(1)->and($options['connect_timeout'])->toEqual(1)
        ->and($options['allow_redirects'])->toBeFalse();
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('enforces record and page limits', function (string $limit): void {
    config(['b2inst.'.$limit => 1]);
    $first = Fixture::page([Fixture::record()], 2, '?page=2&size=1&sort=newest&allversions=false');
    Http::fakeSequence()->push($first);
    expect(fn () => app(B2instClient::class)->records())->toThrow(RuntimeException::class, 'limit');
})->with(['max_records', 'max_pages']);
