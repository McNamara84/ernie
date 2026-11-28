<?php

declare(strict_types=1);

use App\Services\DataCitePublicApiLimiterService;
use App\Services\Pidinst\DataCiteInstrumentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\DataCiteInstrumentFixture as Fixture;

covers(DataCiteInstrumentClient::class);

beforeEach(function (): void {
    Cache::flush();
    config(['pidinst.datacite.host' => 'https://datacite.example.test', 'pidinst.datacite.page_size' => 1, 'pidinst.datacite.retry_delay' => 0]);
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
});

it('reads cursor pages anonymously and revalidates the first page', function (): void {
    $first = Fixture::page([Fixture::record()], 2, '?page[cursor]=second&page[size]=1&resource-type-id=instrument');
    Http::fakeSequence()->push($first)->push(Fixture::page([Fixture::record('10.1234/second')], 2))->push($first);
    expect(app(DataCiteInstrumentClient::class)->records()['records'])->toHaveCount(2);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'resource-type-id=instrument')
        && ! $request->hasHeader('Authorization') && $request->hasHeader('Accept', 'application/vnd.api+json') && $request->hasHeader('User-Agent'));
});

it('rejects changed scope and unsafe pagination before fetching the next page', function (string $next): void {
    Http::fakeSequence()->push(Fixture::page([Fixture::record()], 2, $next));
    expect(fn () => app(DataCiteInstrumentClient::class)->records())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([
    'https://evil.test/dois?resource-type-id=instrument&page[cursor]=2&page[size]=1',
    'http://datacite.example.test/dois?resource-type-id=instrument&page[cursor]=2&page[size]=1',
    'https://user@datacite.example.test/dois?resource-type-id=instrument&page[cursor]=2&page[size]=1',
    'https://datacite.example.test:444/dois?resource-type-id=instrument&page[cursor]=2&page[size]=1',
    '/clients?resource-type-id=instrument&page[cursor]=2&page[size]=1',
    '?resource-type-id=dataset&page[cursor]=2&page[size]=1', '?resource-type-id=instrument&page[number]=2&page[size]=1',
    '?resource-type-id=instrument&page[cursor]=2&page[size]=5', '?resource-type-id=instrument&page[cursor]=2&page[size]=1&query=x',
    '?resource-type-id=instrument&page[cursor]=2&page[size]=1#x', '?resource-type-id=instrument&page[cursor]=&page[size]=1',
]);

it('validates the search envelope', function (array $payload): void {
    Http::fakeSequence()->push($payload);
    expect(fn () => app(DataCiteInstrumentClient::class)->count())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([[[]], [['data' => []]], [['data' => [], 'meta' => ['total' => '0']]], [['data' => [], 'meta' => ['total' => -1]]],
    [['data' => 'bad', 'meta' => ['total' => 0]]], [['data' => [], 'meta' => ['total' => 0], 'links' => ['next' => 3]]]]);

it('rejects mismatched identities or records outside the requested public type', function (string $field, mixed $value): void {
    $record = Fixture::record();
    data_set($record, $field, $value);
    Http::fakeSequence()->push(Fixture::page([$record]));
    expect(fn () => app(DataCiteInstrumentClient::class)->count())->toThrow(RuntimeException::class, 'search record');
})->with([['id', null], ['id', 'not-a-doi'], ['type', 'clients'], ['attributes', null], ['attributes.doi', '10.1234/mismatch'],
    ['attributes.state', 'registered'], ['attributes.state', 'draft'], ['attributes.types.resourceTypeGeneral', 'PhysicalObject']]);

it('rejects incomplete or moving snapshots after one restart', function (string $case): void {
    $first = Fixture::page([Fixture::record()], 2, '?resource-type-id=instrument&page[cursor]=second&page[size]=1');
    $second = match ($case) {
        'duplicate' => Fixture::page([Fixture::record()], 2),
        'total' => Fixture::page([Fixture::record('10.1234/second')], 3),
        'empty' => Fixture::page([], 2),
        'loop' => Fixture::page([Fixture::record('10.1234/second')], 2, $first['links']['next']),
        default => Fixture::page([Fixture::record('10.1234/second')], 2),
    };
    if ($case === 'early') {
        $first['links']['next'] = null;
        Http::fakeSequence()->push($first)->push($first);
    } else {
        Http::fakeSequence()->push($first)->push($second)->push($first)->push($second);
    }
    expect(fn () => app(DataCiteInstrumentClient::class)->records())->toThrow(RuntimeException::class);
})->with(['duplicate', 'total', 'empty', 'early', 'loop']);

it('restarts on first-page metadata revision changes without resetting the deadline', function (): void {
    $old = Fixture::page([Fixture::record()]);
    $new = Fixture::page([Fixture::record(attributes: ['metadataVersion' => 2])]);
    Http::fakeSequence()->push($old)->push($new)->push($new)->push($new);
    expect(app(DataCiteInstrumentClient::class)->records()['records'][0]['attributes']['metadataVersion'])->toBe(2);
    Http::assertSentCount(4);
});

it('bounds retries and respects public cooldowns', function (int $status): void {
    Http::fakeSequence()->push([], $status, ['Retry-After' => '2'])->push([], $status, ['Retry-After' => '2'])->push(Fixture::page());
    expect(app(DataCiteInstrumentClient::class)->count())->toBe(0);
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
})->with([429, 500, 502, 503, 504]);

it('retries connection failures at most three times', function (): void {
    Http::fake(['*' => Http::failedConnection()]);
    expect(fn () => app(DataCiteInstrumentClient::class)->count())->toThrow(RuntimeException::class, 'unavailable');
    Http::assertSentCount(3);
});

it('does not follow redirects or retry permanent errors', function (int $status): void {
    Http::fakeSequence()->push([], $status, ['Location' => 'https://evil.test']);
    expect(fn () => app(DataCiteInstrumentClient::class)->count())->toThrow(RuntimeException::class, 'HTTP '.$status);
    Http::assertSentCount(1);
})->with([301, 302, 307, 400, 401, 403, 404]);

it('fails before an HTTP request during an existing public cooldown', function (): void {
    app(DataCitePublicApiLimiterService::class)->suspend(60);
    expect(fn () => app(DataCiteInstrumentClient::class)->count())->toThrow(RuntimeException::class, 'cooldown');
    Http::assertNothingSent();
});

it('bounds retry-after by the shared deadline', function (): void {
    Http::fakeSequence()->push([], 429, ['Retry-After' => '1200']);
    expect(fn () => app(DataCiteInstrumentClient::class)->count())->toThrow(RuntimeException::class, 'time budget');
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('rejects expired deadlines and invalid configuration without fetching', function (string $option, mixed $value): void {
    $client = app(DataCiteInstrumentClient::class);
    if ($option === 'deadline') {
        $client->startBudget(microtime(true) - 1);
    } else {
        config(['pidinst.datacite.'.$option => $value]);
    }
    expect(fn () => $client->records())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([['deadline', 0], ['host', 'http://api.datacite.org'], ['host', 'https://user:pass@api.datacite.org'],
    ['host', 'https://api.datacite.org/path'], ['host', 'https://api.datacite.org?x=1'], ['page_size', 0], ['page_size', 1001]]);

it('enforces page and record limits', function (string $option): void {
    config(['pidinst.datacite.'.$option => 1]);
    Http::fakeSequence()->push(Fixture::page([Fixture::record()], 2, '?resource-type-id=instrument&page[cursor]=second&page[size]=1'));
    expect(fn () => app(DataCiteInstrumentClient::class)->records())->toThrow(RuntimeException::class, 'limit');
})->with(['max_pages', 'max_records']);

it('rejects a successful response containing HTML instead of JSON', function (): void {
    Http::fakeSequence()->push('<html>Unavailable</html>', 200, ['Content-Type' => 'text/html']);
    expect(fn () => app(DataCiteInstrumentClient::class)->count())->toThrow(RuntimeException::class, 'Invalid JSON');
});
