<?php

declare(strict_types=1);

use App\Console\Commands\BackfillIgsnClassifications;
use App\Enums\CacheKey;
use App\Enums\Igsn\IgsnClassificationType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\Igsn\IgsnClassificationBackfillService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

covers(BackfillIgsnClassifications::class, IgsnClassificationBackfillService::class);

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

function issue1210BackfillResource(string $handle): Resource
{
    $resource = Resource::factory()->create(['doi' => '10.60510/'.strtolower($handle)]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        'material' => 'Rock',
    ]);

    return $resource;
}

/** @param array<string, string|null> $documents */
function issue1210FakePortal(array $documents): void
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

it('is dry-run first then applies additively and idempotently with targeted cache invalidation', function (): void {
    $resource = issue1210BackfillResource('ICDP5054ES1O201');
    IgsnClassification::create([
        'resource_id' => $resource->id,
        'value' => 'Curated classification',
        'position' => 2,
    ]);
    $untyped = IgsnClassification::create([
        'resource_id' => $resource->id,
        'value' => 'Igneous',
        'position' => 4,
    ]);
    IgsnClassification::create([
        'resource_id' => $resource->id,
        'value' => 'Unknown',
        'classification_type' => IgsnClassificationType::BIOLOGY,
        'position' => 5,
    ]);
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());
    $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
    $cache->put($cacheKey, ['cached' => true], 600);

    issue1210FakePortal([
        'ICDP5054ES1O201' => <<<'XML'
        <resource><supplementalMetadata>
          <record><sample><material>Rock</material><classification>fault related rocks</classification></sample></record>
          <record><sample><material>Rock</material><classification>FAULT RELATED ROCKS;Igneous;Unknown</classification></sample></record>
        </supplementalMetadata></resource>
        XML,
    ]);

    $dryRun = app(IgsnClassificationBackfillService::class)->run();
    expect($dryRun)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'unchanged' => 0,
        'inserted' => 1,
        'types_filled' => 1,
        'conflicts' => 1,
        'errors' => 0,
    ])->and($dryRun['records'][0])->toMatchArray([
        'status' => 'would_update',
        'inserted_values' => 'fault related rocks',
        'types_filled' => 'Igneous',
    ])->and($resource->igsnClassifications()->count())->toBe(3)
        ->and($untyped->fresh()->classification_type)->toBeNull()
        ->and($cache->has($cacheKey))->toBeTrue();

    $applied = app(IgsnClassificationBackfillService::class)->run(apply: true);
    $stored = $resource->igsnClassifications()->orderBy('position')->get();
    expect($applied)->toMatchArray([
        'changed' => 1,
        'inserted' => 1,
        'types_filled' => 1,
        'conflicts' => 1,
        'errors' => 0,
    ])->and($stored->pluck('value')->all())->toBe([
        'Curated classification',
        'Igneous',
        'Unknown',
        'fault related rocks',
    ])->and($stored->pluck('position')->all())->toBe([2, 4, 5, 6])
        ->and($untyped->fresh()->classification_type)->toBe(IgsnClassificationType::ROCK)
        ->and($cache->has($cacheKey))->toBeFalse();

    $cache->put($cacheKey, ['cached' => true], 600);
    $secondApply = app(IgsnClassificationBackfillService::class)->run(apply: true);
    expect($secondApply)->toMatchArray([
        'changed' => 0,
        'unchanged' => 1,
        'inserted' => 0,
        'types_filled' => 0,
        'conflicts' => 1,
        'errors' => 0,
    ])->and($resource->igsnClassifications()->count())->toBe(4)
        ->and($cache->has($cacheKey))->toBeTrue();
});

it('isolates invalid identifiers malformed XML missing DIF and rejected values', function (): void {
    $good = issue1210BackfillResource('ICDP5052GOOD001');
    issue1210BackfillResource('ICDP5052BADXML');
    issue1210BackfillResource('ICDP5052MISSING');
    $invalid = Resource::factory()->create(['doi' => '10.99999/not-an-igsn']);
    IgsnMetadata::create(['resource_id' => $invalid->id]);
    issue1210FakePortal([
        'ICDP5052GOOD001' => <<<'XML'
        <resource><sample>
          <material>Rock</material>
          <classification>cataclastic rocks;unknown future class</classification>
        </sample></resource>
        XML,
        'ICDP5052BADXML' => '<not-closed>',
        'ICDP5052MISSING' => null,
    ]);

    $result = app(IgsnClassificationBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'scanned' => 4,
        'changed' => 1,
        'inserted' => 1,
        'missing_dif' => 1,
        'rejected' => 1,
        'errors' => 2,
    ])->and(collect($result['records'])->pluck('status')->sort()->values()->all())
        ->toBe(['error', 'error', 'missing_dif', 'updated'])
        ->and($good->igsnClassifications()->pluck('value')->all())->toBe(['cataclastic rocks'])
        ->and(collect($result['records'])->firstWhere('handle', 'ICDP5052GOOD001')['rejected_values'])
        ->toContain('unknown future class');
});

it('checks every imported IGSN even when a classification already exists', function (): void {
    $icdp = issue1210BackfillResource('ICDP5059GLOBAL1');
    $other = issue1210BackfillResource('GFGLOBAL1210');
    foreach ([$icdp, $other] as $resource) {
        IgsnClassification::create([
            'resource_id' => $resource->id,
            'value' => 'Igneous',
            'classification_type' => IgsnClassificationType::ROCK,
            'position' => 0,
        ]);
    }
    issue1210FakePortal([
        'ICDP5059GLOBAL1' => '<resource><sample><material>Rock</material><classification>Igneous;VOL</classification></sample></resource>',
        'GFGLOBAL1210' => '<resource><sample><material>Rock</material><classification>Igneous;fault related rocks</classification></sample></resource>',
    ]);

    $result = app(IgsnClassificationBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray(['scanned' => 2, 'changed' => 2, 'inserted' => 2, 'errors' => 0])
        ->and($icdp->igsnClassifications()->orderBy('position')->pluck('value')->all())->toBe(['Igneous', 'VOL'])
        ->and($other->igsnClassifications()->orderBy('position')->pluck('value')->all())
        ->toBe(['Igneous', 'fault related rocks']);
});

it('honors filtering resume and limit options and writes a complete command report', function (): void {
    $first = issue1210BackfillResource('ICDP1210FILTERA');
    $second = issue1210BackfillResource('ICDP1210FILTERB');
    issue1210BackfillResource('ICDP1210FILTERC');
    issue1210FakePortal([
        'ICDP1210FILTERB' => '<resource><sample><material>Rock</material><classification>metamorphic rocks</classification></sample></resource>',
    ]);
    $reportPath = storage_path('framework/testing/issue-1210-'.Str::uuid().'.csv');

    try {
        $this->artisan('igsn:backfill-classifications', [
            '--doi' => ['ICDP1210FILTERB'],
            '--after-id' => $first->id,
            '--limit' => 1,
            '--chunk' => 500,
            '--report' => $reportPath,
        ])->expectsOutputToContain('Dry run only; no data was changed.')
            ->assertExitCode(Command::SUCCESS);

        expect($second->igsnClassifications()->count())->toBe(0)
            ->and(File::get($reportPath))
            ->toContain('resource_id,doi,handle,status,existing_values,source_values,inserted_values,types_filled,rejected_values,conflicts,message')
            ->toContain('ICDP1210FILTERB')
            ->toContain('metamorphic rocks')
            ->toContain('would_update');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) $request->data()['query'], $query);

            return str_contains((string) ($query['q'] ?? ''), 'ICDP1210FILTERB')
                && ! str_contains((string) ($query['q'] ?? ''), 'ICDP1210FILTERA')
                && (int) ($query['rows'] ?? 0) <= 100;
        });
    } finally {
        File::delete($reportPath);
    }
});

it('returns a failure status when the legacy portal request fails', function (): void {
    issue1210BackfillResource('ICDP1210PORTALFAIL');
    Http::fake(['igsn-portal.example.test/*' => Http::response('unavailable', 503)]);

    $this->artisan('igsn:backfill-classifications')
        ->expectsOutputToContain('Dry run only; no data was changed.')
        ->assertExitCode(Command::FAILURE);
});
