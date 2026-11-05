<?php

declare(strict_types=1);

use App\Console\Commands\GetPid4instInstruments;
use App\Http\Controllers\EditorController;
use App\Jobs\UpdatePidJob;
use App\Models\PidSetting;
use App\Models\ResourceInstrument;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\InstrumentNameResolutionService;
use App\Services\Pid4instStatusService;
use App\Services\Pid4instVocabularyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\B2instFixture as Fixture;

covers(GetPid4instInstruments::class);

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
    config(['b2inst.host' => 'https://b2inst.example.test', 'b2inst.retry_delay' => 0]);
    $this->setting = PidSetting::updateOrCreate(['type' => 'pid4inst'], ['display_name' => 'PID4INST', 'is_active' => true, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);
    // The ID comes from the reported failure; the Handle and descriptive fields are synthetic.
    $this->invalid = Fixture::record('44jr3-qtj89');
    unset($this->invalid['pids']['epic']);
    $this->old = json_encode(['lastUpdated' => '2026-01-01T00:00:00Z', 'total' => 1, 'data' => [[
        'id' => $this->invalid['id'], 'pid' => '21.T11975/44jr3-qtj89', 'pidType' => 'Handle', 'name' => 'Curated instrument',
    ]]], JSON_THROW_ON_ERROR);
    Storage::put(Pid4instVocabularyService::FILE, $this->old);
});

it('publishes valid instruments while excluding unregistered new or existing records', function (bool $existing, int $httpStatus): void {
    if (! $existing) {
        Storage::delete(Pid4instVocabularyService::FILE);
    }
    Http::fake([
        'https://b2inst.example.test/api/records*' => Http::response(Fixture::page([$this->invalid, Fixture::record('valid')])),
        'https://hdl.handle.net/*' => Http::response(['responseCode' => 100], $httpStatus),
    ]);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0);
    $payload = json_decode(Storage::get(Pid4instVocabularyService::FILE), true);
    expect($payload['total'])->toBe(1)->and(array_column($payload['data'], 'id'))->toBe(['valid'])
        ->and($payload['_sync']['serverCount'])->toBe(2)->and($payload['_sync']['readCount'])->toBe(2)
        ->and($payload['_sync']['excluded'][0])->toMatchArray(['id' => '44jr3-qtj89', 'reasonCode' => 'unregistered_handle']);
    $service = app(Pid4instStatusService::class);
    expect($service->compareWithRemote($this->setting)['updateAvailable'])->toBeFalse()
        ->and($service->getLocalStatus($this->setting)['excludedCount'])->toBe(1);
    $vocabulary = app(Pid4instVocabularyService::class);
    for ($i = 0; $i < 2; $i++) {
        expect($vocabulary->getPublicPayload())->not->toHaveKey('_sync')
            ->and($vocabulary->getPublicPayload()['data'][0])->not->toHaveKey('lookupIdentifiers');
    }
})->with([true, false])->with([200, 404]);

it('counts excluded records towards pagination without requesting an extra page', function (): void {
    config(['b2inst.page_size' => 1]);
    $last = Fixture::record('last');
    unset($last['pids']['epic']);
    $records = [$this->invalid, Fixture::record('valid'), $last];
    Http::fake([
        'https://b2inst.example.test/api/records*' => function (Request $request) use ($records) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) $query['page'];
            expect($page)->toBeLessThanOrEqual(3);

            return Http::response(Fixture::page([$records[$page - 1]], 3, $page < 3 ? '?page='.($page + 1).'&size=1&sort=newest&allversions=false' : null));
        },
        'https://hdl.handle.net/*' => Http::response(['responseCode' => 100], 404),
    ]);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0);
    $snapshot = json_decode(Storage::get(Pid4instVocabularyService::FILE), true);
    expect($snapshot['total'])->toBe(1)->and($snapshot['_sync']['readCount'])->toBe(3)
        ->and($snapshot['_sync']['excluded'])->toHaveCount(2);
});

it('keeps the previous file and cached vocabulary when Handle verification is uncertain', function (string $failure): void {
    $vocabulary = app(Pid4instVocabularyService::class);
    $oldCached = $vocabulary->getPublicPayload();
    Http::fake([
        'https://b2inst.example.test/api/records*' => Http::response(Fixture::page([$this->invalid, Fixture::record('valid')])),
        'https://hdl.handle.net/*' => match ($failure) {
            'network' => Http::failedConnection(),
            'http' => Http::response(['responseCode' => 100], 503),
            'html404' => Http::response('<h1>Not found</h1>', 404),
            'unknown' => Http::response(['responseCode' => 2]),
            'ambiguous' => Http::response(['responseCode' => 1, 'values' => []]),
            'conflict' => Http::response(Fixture::handle('https://unrelated.example.test/record')),
        },
    ]);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)
        ->and(Storage::get(Pid4instVocabularyService::FILE))->toBe($this->old)
        ->and($vocabulary->getPublicPayload())->toBe($oldCached);
})->with(['network', 'http', 'html404', 'unknown', 'ambiguous', 'conflict']);

it('preserves a nonempty registry when every record is excluded', function (): void {
    Http::fake([
        'https://b2inst.example.test/api/records*' => Http::response(Fixture::page([$this->invalid])),
        'https://hdl.handle.net/*' => Http::response(['responseCode' => 100], 404),
    ]);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)
        ->and(Storage::get(Pid4instVocabularyService::FILE))->toBe($this->old);
});

it('reinstates an instrument and clears diagnostics after registration and negative cache expiry', function (): void {
    $registered = false;
    Http::fake([
        'https://b2inst.example.test/api/records*' => Http::response(Fixture::page([$this->invalid, Fixture::record('valid')])),
        'https://hdl.handle.net/*' => function () use (&$registered) {
            return $registered ? Http::response(Fixture::handle($this->invalid['metadata']['LandingPage'])) : Http::response(['responseCode' => 100], 404);
        },
    ]);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0);
    $registered = true;
    $this->travel(61)->seconds();
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0);
    $status = app(Pid4instStatusService::class)->getLocalStatus($this->setting);
    expect($status['excludedCount'])->toBe(0)->and($status['itemCount'])->toBe(2);
});

it('reports actual published counts and exclusions through the job and reloaded settings', function (): void {
    Http::fake([
        'https://b2inst.example.test/api/records*' => Http::response(Fixture::page([$this->invalid, Fixture::record('valid')])),
        'https://hdl.handle.net/*' => Http::response(['responseCode' => 100], 404),
    ]);
    $id = Str::uuid()->toString();
    (new UpdatePidJob('pid4inst', $id))->handle();
    $status = Cache::get(UpdatePidJob::getCacheKey($id));
    expect($status)->toMatchArray(['status' => 'completed', 'itemCount' => 1, 'excludedCount' => 1, 'refreshPending' => false])
        ->and($status['exclusions'])->toBe([['id' => '44jr3-qtj89', 'reasonCode' => 'unregistered_handle']]);
    $this->actingAs(User::factory()->admin()->create())->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('pidSettings', fn ($settings): bool => collect($settings)->contains(fn ($setting): bool => $setting['type'] === 'pid4inst' && $setting['excludedCount'] === 1 && $setting['itemCount'] === 1)));
});

it('preserves stored instrument links and curated names when an instrument is excluded', function (): void {
    $link = ResourceInstrument::factory()->create(['instrument_pid' => '21.T11975/44jr3-qtj89', 'instrument_pid_type' => 'Handle', 'instrument_name' => 'Curated instrument']);
    $before = $link->refresh()->getAttributes();
    Http::fake([
        'https://b2inst.example.test/api/records*' => Http::response(Fixture::page([$this->invalid, Fixture::record('valid')])),
        'https://hdl.handle.net/*' => Http::response(['responseCode' => 100], 404),
    ]);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0)->and($link->refresh()->getAttributes())->toBe($before);
    $selection = ['pid' => $link->instrument_pid, 'pidType' => $link->instrument_pid_type, 'name' => $link->instrument_name];
    expect(app(InstrumentNameResolutionService::class)->resolve([$selection])[0])->toMatchArray($selection + ['resolution' => ['status' => 'not_found']]);
    $resource = $link->resource;
    $this->actingAs(User::factory()->admin()->create());
    $token = $this->get('/editor?resourceId='.$resource->id)->assertOk()->inertiaProps('editorLoad.token');
    $instruments = $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get('/editor?resourceId='.$resource->id)->assertOk()->inertiaProps('instruments');
    expect($instruments[0])->toMatchArray($selection);
    $this->postJson('/editor/resources/draft', [
        'resourceId' => $resource->id, 'titles' => [['title' => 'Excluded instrument regression', 'titleType' => 'MainTitle']],
        'instruments' => $instruments,
    ])->assertOk();
    $resource->refresh();
    expect($resource->instruments)->toHaveCount(1)
        ->and($resource->instruments[0]->instrument_pid)->toBe($selection['pid'])
        ->and($resource->instruments[0]->instrument_name)->toBe($selection['name']);
    $json = app(DataCiteJsonExporter::class)->export($resource)['data']['attributes'];
    expect($json['relatedIdentifiers'])->toContain([
        'relatedIdentifier' => $selection['pid'], 'relatedIdentifierType' => 'Handle', 'relationType' => 'IsCollectedBy',
    ])->and(app(DataCiteXmlExporter::class)->export($resource))->toContain($selection['pid']);
});

it('can preview exclusions without replacing the snapshot', function (): void {
    Http::fake([
        'https://b2inst.example.test/api/records*' => Http::response(Fixture::page([$this->invalid, Fixture::record('valid')])),
        'https://hdl.handle.net/*' => Http::response(['responseCode' => 100], 404),
    ]);
    expect(Artisan::call('get-pid4inst-instruments', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('unregistered_handle')
        ->and(Storage::get(Pid4instVocabularyService::FILE))->toBe($this->old);
});
