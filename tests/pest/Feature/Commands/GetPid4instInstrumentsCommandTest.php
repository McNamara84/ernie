<?php

declare(strict_types=1);

use App\Console\Commands\GetPid4instInstruments;
use App\Enums\CacheKey;
use App\Jobs\UpdatePidJob;
use App\Models\PidSetting;
use App\Models\ResourceInstrument;
use App\Services\B2inst\B2instSnapshotStore;
use App\Services\B2inst\B2instSyncService;
use App\Services\DataCiteXmlExporter;
use App\Services\Pid4instStatusService;
use App\Services\VocabularyCacheService;
use App\Services\Xml\Sections\RelatedWorkAndInstrumentSectionParser;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\XmlReader;
use Tests\Fixtures\B2instFixture as Fixture;

covers(GetPid4instInstruments::class, B2instSyncService::class, B2instSnapshotStore::class);

beforeEach(function (): void {
    config(['b2inst.host' => 'https://b2inst.example.test', 'b2inst.retry_delay' => 0]);
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
});

it('publishes compatible data with internal provenance and invalidates the cache', function (): void {
    Http::fake(['https://b2inst.example.test/*' => Http::response(Fixture::page([Fixture::record()]))]);
    app(VocabularyCacheService::class)->cacheVocabulary(CacheKey::PID4INST_INSTRUMENTS, fn () => ['old']);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0);
    $payload = app(B2instSnapshotStore::class)->read();
    expect($payload['total'])->toBe(1)
        ->and($payload['data'][0]['pid'])->toBe('21.T11975/abc12-def34')
        ->and($payload['_sync']['records']['abc12-def34']['parentId'])->toBe('parent-abc12-def34')
        ->and($payload['_sync']['refreshPending'])->toBeFalse()
        ->and(app(VocabularyCacheService::class)->cacheVocabulary(CacheKey::PID4INST_INSTRUMENTS, fn () => ['new']))->toBe(['new']);
});

it('updates same-count metadata, preserves saved links and keeps the previous snapshot', function (): void {
    $link = ResourceInstrument::factory()->create(['instrument_pid' => 'https://hdl.handle.net/21.T11975/abc12-def34']);
    $before = $link->fresh()->getAttributes();
    $original = Fixture::page([Fixture::record()]);
    $renamed = Fixture::page([Fixture::record(overrides: ['metadata' => ['Name' => 'Renamed'], 'revision_id' => 3])]);
    Http::fakeSequence()->push($original)->push($original)->push($renamed)->push($renamed);
    $service = app(B2instSyncService::class);
    $service->sync();
    $old = Storage::get(B2instSnapshotStore::FILE);
    $this->travel(1)->minutes();
    $result = $service->sync();
    expect($result['changes']['changed'])->toBe(1)->and($result['itemCount'])->toBe(1)
        ->and($result['storedLinksOutsideSelection'])->toBe(0)
        ->and(app(B2instSnapshotStore::class)->read()['data'][0]['name'])->toBe('Renamed')
        ->and(Storage::get(B2instSnapshotStore::FILE.'.previous'))->toBe($old)
        ->and($link->fresh()->getAttributes())->toBe($before);
});

it('does not report reordered records or usage statistics as metadata changes', function (): void {
    $a = Fixture::record('a');
    $b = Fixture::record('b');
    $original = Fixture::page([$b, $a]);
    $a['stats'] = ['views' => 99];
    $reordered = Fixture::page([$a, $b]);
    Http::fakeSequence()->push($original)->push($original)->push($reordered)->push($reordered);
    $service = app(B2instSyncService::class);
    $first = $service->sync();
    $this->travel(1)->minutes();
    $second = $service->sync();
    expect($second['changed'])->toBeFalse()->and($second['lastUpdated'])->toBe($first['lastUpdated'])
        ->and($second['checkedAt'])->not->toBe($first['checkedAt']);
});

it('supports a dry run without modifying files or caches', function (): void {
    Http::fake(['*' => Http::response(Fixture::page([Fixture::record()]))]);
    Cache::put(CacheKey::PID4INST_INSTRUMENTS->key(), ['old']);
    expect(Artisan::call('get-pid4inst-instruments', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('"published": false');
    Storage::assertMissing(B2instSnapshotStore::FILE);
    expect(Cache::get(CacheKey::PID4INST_INSTRUMENTS->key()))->toBe(['old']);
});

it('keeps the previous file on late HTTP errors, incomplete pages, invalid records and empty candidates', function (string $failure): void {
    $old = json_encode(['lastUpdated' => '2026-01-01T00:00:00Z', 'total' => 1, 'data' => [['id' => 'abc12-def34', 'pid' => '21.T11975/abc12-def34']]]);
    Storage::put(B2instSnapshotStore::FILE, $old);
    Cache::put(CacheKey::PID4INST_INSTRUMENTS->key(), ['old']);
    if ($failure === 'late HTTP') {
        config(['b2inst.page_size' => 1]);
        Http::fakeSequence()->push(Fixture::page([Fixture::record()], 2, '?page=2&size=1&sort=newest&allversions=false'))->push([], 400);
    } else {
        $records = match ($failure) {
            'empty' => [],
            'invalid' => [Fixture::record(overrides: ['metadata' => ['Identifier' => ['identifierType' => 'ARK']]]), Fixture::record('second')],
            'duplicate PID' => [Fixture::record(), Fixture::record('second', ['metadata' => ['Identifier' => ['identifierValue' => '21.T11975/abc12-def34']], 'pids' => ['epic' => ['identifier' => '21.T11975/abc12-def34']]])],
            default => [Fixture::record()],
        };
        Http::fake(['*' => Http::response(Fixture::page($records, $failure === 'incomplete' ? 2 : count($records)))]);
    }
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)
        ->and(Storage::get(B2instSnapshotStore::FILE))->toBe($old)
        ->and(Cache::get(CacheKey::PID4INST_INSTRUMENTS->key()))->toBe(['old']);
})->with(['late HTTP', 'incomplete', 'empty', 'invalid', 'duplicate PID']);

it('reports excluded new records separately from the server count', function (): void {
    Http::fake(['*' => Http::response(Fixture::page([Fixture::record(), Fixture::record('unknown', ['metadata' => ['Identifier' => ['identifierType' => 'ARK']]])]))]);
    $result = app(B2instSyncService::class)->sync();
    expect($result['serverCount'])->toBe(2)->and($result['itemCount'])->toBe(1)
        ->and($result['excluded'][0]['id'])->toBe('unknown');
    $status = app(Pid4instStatusService::class)->compareWithRemote(new PidSetting(['type' => 'pid4inst']));
    expect($status['localCount'])->toBe(1)->and($status['remoteCount'])->toBe(2)
        ->and($status['updateAvailable'])->toBeFalse();
});

it('refuses concurrent imports including direct commands', function (): void {
    $lock = Cache::lock(B2instSyncService::LOCK, 1200);
    $lock->get();
    Http::fake();
    try {
        expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)
            ->and(Artisan::output())->toContain('already running');
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('preserves the existing snapshot when writing or renaming fails', function (string $operation): void {
    $old = '{"total":1,"data":[]}';
    Storage::put(B2instSnapshotStore::FILE, $old);
    $disk = Storage::disk();
    Storage::shouldReceive('disk')->andReturn($disk);
    foreach (['put', 'get', 'exists', 'move', 'copy', 'delete'] as $method) {
        if ($method === $operation) {
            Storage::shouldReceive($method)->once()->andReturn(false);
        } else {
            Storage::shouldReceive($method)->andReturnUsing(fn (...$args) => $disk->{$method}(...$args));
        }
    }
    expect(fn () => app(B2instSnapshotStore::class)->publish(['total' => 2, 'data' => []]))->toThrow(RuntimeException::class);
    expect($disk->get(B2instSnapshotStore::FILE))->toBe($old)
        ->and($disk->files())->toBe([B2instSnapshotStore::FILE]);
})->with(['put', 'move']);

it('reports publication independently of cache failure and retries without fetching again', function (): void {
    Http::fake(['*' => Http::response(Fixture::page([Fixture::record()]))]);
    $cache = Mockery::mock(VocabularyCacheService::class);
    $cache->shouldReceive('invalidateVocabularyCache')->once()->andThrow(new RuntimeException('cache down'));
    $this->app->instance(VocabularyCacheService::class, $cache);
    $result = app(B2instSyncService::class)->sync();
    expect($result['published'])->toBeTrue()->and($result['refreshPending'])->toBeTrue()
        ->and(app(B2instSnapshotStore::class)->read()['_sync']['refreshPending'])->toBeTrue();
    $this->app->forgetInstance(VocabularyCacheService::class);
    expect(Artisan::call('get-pid4inst-instruments', ['--refresh-caches' => true]))->toBe(0)
        ->and(app(B2instSnapshotStore::class)->read()['_sync']['refreshPending'])->toBeFalse();
    Http::assertSentCount(2);
});

it('reports the published count and timestamp through the background job', function (): void {
    PidSetting::firstOrCreate(['type' => 'pid4inst'], ['display_name' => 'PIDINST']);
    Http::fake(['*' => Http::response(Fixture::page([Fixture::record()]))]);
    $jobId = (string) Str::uuid();
    (new UpdatePidJob('pid4inst', $jobId))->handle();
    $status = Cache::get(UpdatePidJob::getCacheKey($jobId));
    expect($status['status'])->toBe('completed')->and($status['itemCount'])->toBe(1)
        ->and($status['lastUpdated'])->toBe(app(B2instSnapshotStore::class)->read()['lastUpdated']);
});

it('roundtrips a synchronized bare Handle through DataCite XML as IsCollectedBy', function (): void {
    Http::fake(['*' => Http::response(Fixture::page([Fixture::record()]))]);
    app(B2instSyncService::class)->sync();
    $instrument = app(B2instSnapshotStore::class)->read()['data'][0];
    $resource = App\Models\Resource::factory()->create();
    ResourceInstrument::factory()->create([
        'resource_id' => $resource->id, 'instrument_pid' => $instrument['pid'],
        'instrument_pid_type' => $instrument['pidType'], 'instrument_name' => $instrument['name'],
    ]);
    $xml = app(DataCiteXmlExporter::class)->export($resource);
    expect($xml)->toContain('relationType="IsCollectedBy"')->toContain('>21.T11975/abc12-def34</relatedIdentifier>');
    $parsed = app(RelatedWorkAndInstrumentSectionParser::class)
        ->parse(XmlReader::fromString($xml), 'b2inst-roundtrip.xml');
    expect($parsed['instruments'])->toHaveCount(1)
        ->and($parsed['instruments'][0]['pid'])->toBe($instrument['pid'])
        ->and($parsed['instruments'][0]['pidType'])->toBe('Handle');
});

it('does not misreport publication when a cache outage prevents releasing the lock', function (): void {
    Http::fake(['*' => Http::response(Fixture::page([Fixture::record()]))]);
    $this->mock(VocabularyCacheService::class)->shouldReceive('invalidateVocabularyCache')->once();
    $service = app(B2instSyncService::class);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('Cache unavailable'));
    Cache::shouldReceive('lock')->once()->with(B2instSyncService::LOCK, 1200)->andReturn($lock);
    $result = $service->sync();
    expect($result['published'])->toBeTrue()->and($result['refreshPending'])->toBeFalse()
        ->and(app(B2instSnapshotStore::class)->read()['total'])->toBe(1);
});

it('compares legacy DOI forms and preserves case-sensitive URL identities in the link audit', function (): void {
    $records = [];
    foreach ([['DOI', '10.1234/instrument'], ['URL', 'https://example.org/A'], ['URL', 'https://example.org/a']] as $index => [$type, $pid]) {
        $records[] = Fixture::record('record-'.$index, ['metadata' => ['Identifier' => ['identifierType' => $type, 'identifierValue' => $pid]]]);
        ResourceInstrument::factory()->create(['instrument_pid_type' => $type, 'instrument_pid' => $type === 'DOI' ? 'https://doi.org/'.$pid : $pid]);
    }
    ResourceInstrument::factory()->create(['instrument_pid_type' => 'Handle', 'instrument_pid' => 'invalid']);
    Http::fake(['*' => Http::response(Fixture::page($records))]);
    $result = app(B2instSyncService::class)->sync(true);
    expect($result['itemCount'])->toBe(3)->and($result['storedLinksOutsideSelection'])->toBe(1);
});
