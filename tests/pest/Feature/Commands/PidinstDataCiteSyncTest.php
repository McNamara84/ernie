<?php

declare(strict_types=1);

use App\Jobs\UpdatePidJob;
use App\Models\PidSetting;
use App\Models\ResourceInstrument;
use App\Services\B2inst\B2instClient;
use App\Services\InstrumentNameResolutionService;
use App\Services\Pid4instStatusService;
use App\Services\Pid4instVocabularyService;
use App\Services\Pidinst\PidinstSnapshotStore;
use App\Services\Pidinst\PidinstSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\B2instFixture;
use Tests\Fixtures\DataCiteInstrumentFixture as DataCite;

covers(PidinstSyncService::class, PidinstSnapshotStore::class);

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
    config(['b2inst.host' => 'https://b2inst.example.test', 'b2inst.retry_delay' => 0,
        'pidinst.datacite.host' => 'https://datacite.example.test', 'pidinst.datacite.retry_delay' => 0]);
    $this->setting = PidSetting::updateOrCreate(['type' => 'pid4inst'], ['display_name' => 'PIDINST', 'is_active' => true, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);
    $this->b2Records = [B2instFixture::record()];
    $this->dcRecords = [DataCite::record()];
    $this->errorSource = null;
    $this->lateSource = null;
    Http::fake(function (Request $request) {
        $source = str_starts_with($request->url(), 'https://datacite.example.test/') ? 'datacite' : 'b2inst';
        if ($this->errorSource === $source) {
            return Http::response([], 400);
        }
        if ($this->lateSource === $source) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($source === 'datacite' ? ($query['page']['cursor'] ?? '1') : ($query['page'] ?? '1')) !== '1') {
                return Http::response([], 400);
            }

            return Http::response($source === 'datacite'
                ? DataCite::page($this->dcRecords, 2, '?resource-type-id=instrument&page[cursor]=second&page[size]=1000')
                : B2instFixture::page($this->b2Records, 2, '?page=2&size=100&sort=newest&allversions=false'));
        }

        return Http::response($source === 'datacite' ? DataCite::page($this->dcRecords) : B2instFixture::page($this->b2Records));
    });
});

it('publishes both sources with private provenance and truthful source counts', function (): void {
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0);
    $snapshot = app(PidinstSnapshotStore::class)->read();
    expect($snapshot['total'])->toBe(2)->and($snapshot['_sync']['version'])->toBe(2)
        ->and($snapshot['_sync']['sources']['b2inst']['serverCount'])->toBe(1)
        ->and($snapshot['_sync']['sources']['datacite']['acceptedCount'])->toBe(1)
        ->and($snapshot['_sync']['deduplicatedCount'])->toBe(0);
    $public = app(Pid4instVocabularyService::class)->getPublicPayload();
    expect(array_column($public['data'], 'pidType'))->toBe(['Handle', 'DOI'])
        ->and($public)->not->toHaveKey('_sync');
    foreach ($public['data'] as $record) {
        expect($record)->not->toHaveKeys(['origins', 'lookupIdentifiers', 'source']);
    }
    Http::assertSentCount(4);
});

it('deduplicates identical DOIs with complete B2INST metadata precedence', function (string $pid): void {
    $this->b2Records = [B2instFixture::record(overrides: ['metadata' => ['Identifier' => ['identifierType' => 'DOI', 'identifierValue' => $pid]]])];
    $result = app(PidinstSyncService::class)->sync();
    $snapshot = app(PidinstSnapshotStore::class)->read();
    expect($result['itemCount'])->toBe(1)->and($result['serverCount'])->toBe(2)->and($result['deduplicatedCount'])->toBe(1)
        ->and($snapshot['data'][0]['id'])->toBe('abc12-def34')
        ->and($snapshot['data'][0]['name'])->toBe('Test instrument abc12-def34')
        ->and($snapshot['data'][0]['model'])->toBe('M-1')
        ->and($snapshot['_sync']['records']['abc12-def34']['origins'])->toBe([
            ['source' => 'b2inst', 'id' => 'abc12-def34'], ['source' => 'datacite', 'id' => 'datacite:10.1234/sensor'],
        ]);
    $status = app(Pid4instStatusService::class)->compareWithRemote($this->setting);
    expect($status['localCount'])->toBe(1)->and($status['remoteCount'])->toBe(2)->and($status['updateAvailable'])->toBeFalse();
})->with(['10.1234/sensor', '10.1234/SENSOR', 'https://doi.org/10.1234/Sensor', 'http://dx.doi.org/10.1234/SENSOR']);

it('keeps different PIDs despite identical names', function (): void {
    $this->dcRecords[0]['attributes']['titles'][0]['title'] = $this->b2Records[0]['metadata']['Name'];
    expect(app(PidinstSyncService::class)->sync()['itemCount'])->toBe(2);
});

it('rejects B2INST IDs in the reserved DataCite namespace instead of overwriting an entry', function (): void {
    $this->b2Records = [B2instFixture::record('datacite:10.1234/sensor')];
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)->and(Artisan::output())->toContain('reserved DataCite prefix');
    Storage::assertMissing(PidinstSnapshotStore::FILE);
});

it('detects changed DataCite metadata at equal counts and preserves saved links and names', function (): void {
    app(PidinstSyncService::class)->sync();
    $old = Storage::get(PidinstSnapshotStore::FILE);
    $link = ResourceInstrument::factory()->create(['instrument_pid' => 'https://doi.org/10.1234/SENSOR', 'instrument_pid_type' => 'DOI', 'instrument_name' => 'Curated sensor']);
    $before = $link->refresh()->getAttributes();
    $this->dcRecords[0]['attributes']['titles'][0]['title'] = 'New sensor title';
    $this->travel(1)->minutes();
    expect(app(PidinstSyncService::class)->sync()['changes']['changed'])->toBe(1)
        ->and(Storage::get(PidinstSnapshotStore::FILE.'.previous'))->toBe($old)
        ->and($link->refresh()->getAttributes())->toBe($before);
    $resolved = app(InstrumentNameResolutionService::class)->resolve([
        ['pid' => $link->instrument_pid, 'pidType' => 'DOI', 'name' => $link->instrument_pid],
        ['pid' => $link->instrument_pid, 'pidType' => 'DOI', 'name' => $link->instrument_name],
    ]);
    expect(array_column($resolved, 'name'))->toBe(['New sensor title', 'Curated sensor']);
});

it('keeps the entire old snapshot and its cache if either source fails', function (string $source, bool $late): void {
    app(PidinstSyncService::class)->sync();
    $old = Storage::get(PidinstSnapshotStore::FILE);
    $cached = app(Pid4instVocabularyService::class)->getPublicPayload();
    if ($late) {
        $this->lateSource = $source;
    } else {
        $this->errorSource = $source;
    }
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)
        ->and(Storage::get(PidinstSnapshotStore::FILE))->toBe($old)
        ->and(app(Pid4instVocabularyService::class)->getPublicPayload())->toBe($cached);
})->with(['b2inst', 'datacite'])->with([false, true]);

it('protects a previously nonempty source even when the other source is still populated', function (string $source): void {
    app(PidinstSyncService::class)->sync();
    $old = Storage::get(PidinstSnapshotStore::FILE);
    if ($source === 'datacite') {
        $this->dcRecords = [];
    } else {
        $this->b2Records = [];
    }
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)->and(Artisan::output())->toContain('empty')
        ->and(Storage::get(PidinstSnapshotStore::FILE))->toBe($old);
})->with(['datacite', 'b2inst']);

it('excludes invalid new titles but refuses to lose an existing or shadowed DataCite record', function (bool $shadowed): void {
    $this->dcRecords[] = DataCite::record('10.1234/invalid', ['titles' => []]);
    if ($shadowed) {
        $this->b2Records = [B2instFixture::record(overrides: ['metadata' => ['Identifier' => ['identifierType' => 'DOI', 'identifierValue' => '10.1234/sensor']]])];
    }
    app(PidinstSyncService::class)->sync();
    $snapshot = app(PidinstSnapshotStore::class)->read();
    expect($snapshot['_sync']['sources']['datacite']['serverCount'])->toBe(2)
        ->and($snapshot['_sync']['sources']['datacite']['acceptedCount'])->toBe(1)
        ->and($snapshot['_sync']['excluded'][0])->toMatchArray(['source' => 'datacite', 'id' => 'datacite:10.1234/invalid']);
    $old = Storage::get(PidinstSnapshotStore::FILE);
    $this->dcRecords[0]['attributes']['titles'] = [];
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid DATACITE record would remove an existing selection')
        ->and(Storage::get(PidinstSnapshotStore::FILE))->toBe($old);
})->with([false, true]);

it('previews the combined candidate without publishing or invalidating the offered vocabulary', function (): void {
    $old = json_encode(['total' => 1, 'data' => [['id' => 'abc12-def34', 'pid' => '21.T11975/abc12-def34', 'pidType' => 'Handle', 'name' => 'Old name']]]);
    Storage::put(PidinstSnapshotStore::FILE, $old);
    $cached = app(Pid4instVocabularyService::class)->getPublicPayload();
    $result = app(PidinstSyncService::class)->sync(true);
    expect($result['published'])->toBeFalse()->and($result['itemCount'])->toBe(2)
        ->and(Storage::get(PidinstSnapshotStore::FILE))->toBe($old)
        ->and(app(Pid4instVocabularyService::class)->getPublicPayload())->toBe($cached);
});

it('reports the actual deduplicated count through the job and identifies failed sources', function (): void {
    $jobId = (string) Str::uuid();
    (new UpdatePidJob('pid4inst', $jobId))->handle();
    expect(Cache::get(UpdatePidJob::getCacheKey($jobId)))->toMatchArray(['status' => 'completed', 'itemCount' => 2]);
    $this->errorSource = 'datacite';
    (new UpdatePidJob('pid4inst', $jobId))->handle();
    $failed = Cache::get(UpdatePidJob::getCacheKey($jobId));
    expect($failed['status'])->toBe('failed')->and($failed['error'])->toContain('DataCite')
        ->and(app(PidinstSnapshotStore::class)->read()['total'])->toBe(2);
});

it('uses one combined time budget before publication', function (): void {
    config(['pidinst.max_duration' => 1]);
    $client = Mockery::mock(B2instClient::class)->makePartial();
    $client->shouldReceive('startBudget')->once()->with(Mockery::on(fn (float $deadline): bool => $deadline <= microtime(true) + 1));
    $client->shouldReceive('remainingSeconds')->andThrow(new RuntimeException('shared budget exhausted'));
    $this->app->instance(B2instClient::class, $client);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)->and(Artisan::output())->toContain('shared budget exhausted');
    Storage::assertMissing(PidinstSnapshotStore::FILE);
});
