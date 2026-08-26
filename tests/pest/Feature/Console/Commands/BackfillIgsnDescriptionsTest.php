<?php

declare(strict_types=1);

use App\Console\Commands\BackfillIgsnDescriptions;
use App\Enums\CacheKey;
use App\Models\GeoLocation;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\Igsn\IgsnDescriptionBackfillService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

covers(BackfillIgsnDescriptions::class, IgsnDescriptionBackfillService::class);

beforeEach(function (): void {
    Cache::flush();
    Config::set('datacite.production.igsn_prefix', '10.60510');
    Config::set('datacite.legacy_igsn_portal', [
        'proxy_url' => 'https://igsn-portal.example.test/proxy.php',
        'timeout_seconds' => 5,
        'retry_times' => 1,
        'retry_sleep_ms' => 0,
        'retry_jitter_ms' => 0,
        'page_size' => 100,
        'datacenter_cache_ttl_seconds' => 0,
    ]);
});

function issue1167BackfillResource(string $handle, array $descriptionJson = []): Resource
{
    $resource = Resource::factory()->create(['doi' => '10.60510/'.strtolower($handle)]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        'description_json' => $descriptionJson !== [] ? $descriptionJson : null,
    ]);

    return $resource;
}

/** @param array<string, string|null> $documents */
function issue1167FakePortal(array $documents): void
{
    Http::fake([
        'igsn-portal.example.test/*' => Http::response([
            'response' => [
                'numFound' => count($documents),
                'docs' => collect($documents)->map(
                    static fn (?string $xml, string $handle): array => $xml === null
                        ? ['igsn' => $handle, 'has_dif' => false]
                        : ['igsn' => $handle, 'has_dif' => true, 'dif' => base64_encode($xml)],
                )->values()->all(),
            ],
        ]),
    ]);
}

it('is dry-run by default then applies idempotently and invalidates only a changed published page', function (): void {
    $resource = issue1167BackfillResource('GFISSUE1167', [
        'parent_igsn_handle' => 'GFPARENT',
        'custom_key' => ['preserve' => true],
        'material_descriptions' => ['Old value'],
    ]);
    $location = GeoLocation::create(['resource_id' => $resource->id, 'place' => 'Curated place']);
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());
    $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
    $cache->put($cacheKey, ['cached' => true], 600);

    $xml = <<<'XML'
    <resource><sample>
      <material>Unsupported and irrelevant</material>
      <descriptions>
        <description>Core Oriented? 0; RQD Abundance: 0;</description>
        <description descriptionScheme="Rock Type">Quartzite</description>
      </descriptions>
      <locality_description>Northern drill site</locality_description>
    </sample></resource>
    XML;
    issue1167FakePortal(['GFISSUE1167' => $xml]);

    $dryRun = app(IgsnDescriptionBackfillService::class)->run();
    expect($dryRun)->toMatchArray(['scanned' => 1, 'changed' => 1, 'unchanged' => 0, 'missing_dif' => 0, 'errors' => 0])
        ->and($dryRun['records'][0]['status'])->toBe('would_update')
        ->and($resource->igsnMetadata()->first()->description_json['material_descriptions'])->toBe(['Old value'])
        ->and($location->fresh()->locality_description)->toBeNull()
        ->and($cache->has($cacheKey))->toBeTrue();

    $applied = app(IgsnDescriptionBackfillService::class)->run(apply: true);
    $descriptionJson = $resource->igsnMetadata()->first()->description_json;
    expect($applied)->toMatchArray(['scanned' => 1, 'changed' => 1, 'unchanged' => 0, 'missing_dif' => 0, 'errors' => 0])
        ->and($descriptionJson)->toMatchArray([
            'parent_igsn_handle' => 'GFPARENT',
            'custom_key' => ['preserve' => true],
            'description_groups' => [['entries' => [
                ['value' => 'Core Oriented? 0; RQD Abundance: 0;', 'scheme' => null],
                ['value' => 'Quartzite', 'scheme' => 'Rock Type'],
            ]]],
            'material_descriptions' => ['Core Oriented? 0; RQD Abundance: 0;', 'Quartzite'],
        ])->and($location->fresh()->locality_description)->toBe('Northern drill site')
        ->and($cache->has($cacheKey))->toBeFalse();

    $cache->put($cacheKey, ['cached' => true], 600);
    $secondApply = app(IgsnDescriptionBackfillService::class)->run(apply: true);
    expect($secondApply)->toMatchArray(['scanned' => 1, 'changed' => 0, 'unchanged' => 1, 'errors' => 0])
        ->and($cache->has($cacheKey))->toBeTrue();
});

it('isolates malformed records and authoritative missing DIF responses', function (): void {
    $good = issue1167BackfillResource('GFGOOD1167');
    issue1167BackfillResource('GFBAD1167');
    issue1167BackfillResource('GFMISSING1167');
    issue1167FakePortal([
        'GFGOOD1167' => '<resource><sample><descriptions><description>good</description></descriptions></sample></resource>',
        'GFBAD1167' => '<not-closed>',
        'GFMISSING1167' => null,
    ]);

    $result = app(IgsnDescriptionBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray(['scanned' => 3, 'changed' => 1, 'unchanged' => 0, 'missing_dif' => 1, 'errors' => 1])
        ->and(collect($result['records'])->pluck('status')->sort()->values()->all())->toBe(['error', 'missing_dif', 'updated'])
        ->and($good->igsnMetadata()->first()->description_json['description_groups'])->toBe([
            ['entries' => [['value' => 'good', 'scheme' => null]]],
        ]);
});

it('honors DOI filtering resume and limit options and writes a command report', function (): void {
    $first = issue1167BackfillResource('GFFILTERA');
    $second = issue1167BackfillResource('GFFILTERB');
    issue1167BackfillResource('GFFILTERC');
    issue1167FakePortal([
        'GFFILTERA' => '<resource><sample><description>A</description></sample></resource>',
        'GFFILTERB' => '<resource><sample><description>B</description></sample></resource>',
        'GFFILTERC' => '<resource><sample><description>C</description></sample></resource>',
    ]);
    $reportPath = storage_path('framework/testing/issue-1167-'.Str::uuid().'.csv');

    try {
        $this->artisan('igsn:backfill-descriptions', [
            '--doi' => ['GFFILTERB'],
            '--after-id' => $first->id,
            '--limit' => 1,
            '--chunk' => 500,
            '--report' => $reportPath,
        ])->expectsOutputToContain('Dry run only; no data was changed.')
            ->assertExitCode(Command::SUCCESS);

        expect($second->igsnMetadata()->first()->description_json)->toBeNull()
            ->and(File::get($reportPath))->toContain('resource_id,doi,handle,status,descriptions_changed,locality_changed,message')
            ->toContain('GFFILTERB')
            ->toContain('would_update');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) $request->data()['query'], $query);

            return str_contains((string) ($query['q'] ?? ''), 'GFFILTERB')
                && ! str_contains((string) ($query['q'] ?? ''), 'GFFILTERA')
                && (int) ($query['rows'] ?? 0) <= 100;
        });
    } finally {
        File::delete($reportPath);
    }
});

it('preserves a populated locality description while updating descriptions', function (): void {
    $resource = issue1167BackfillResource('GFCURATED1167');
    $location = GeoLocation::create([
        'resource_id' => $resource->id,
        'locality_description' => 'Curated locality',
    ]);
    issue1167FakePortal([
        'GFCURATED1167' => '<resource><sample><description>new</description><locality_description>Imported locality</locality_description></sample></resource>',
    ]);

    $result = app(IgsnDescriptionBackfillService::class)->run(apply: true);

    expect($result['records'][0])->toMatchArray([
        'status' => 'updated',
        'descriptions_changed' => true,
        'locality_changed' => false,
        'message' => 'Existing locality description was preserved.',
    ])->and($location->fresh()->locality_description)->toBe('Curated locality');
});
