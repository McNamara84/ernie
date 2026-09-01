<?php

declare(strict_types=1);

use App\Console\Commands\BackfillLegacyIgsnDifMetadata;
use App\Jobs\SyncImportedResourcesWithDataCiteJob;
use App\Models\Datacenter;
use App\Models\IgsnMetadata;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Services\Igsn\IgsnLegacyDifBackfillService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

covers(BackfillLegacyIgsnDifMetadata::class, IgsnLegacyDifBackfillService::class);

beforeEach(function (): void {
    Cache::flush();
    Config::set('datacite.production.igsn_prefix', '10.60510');
    Config::set('datacite.test_mode', true);
    Config::set('datacite.legacy_igsn_portal', [
        'proxy_url' => 'https://igsn-portal.example.test/proxy.php',
        'timeout_seconds' => 5,
        'retry_times' => 1,
        'retry_sleep_ms' => 0,
        'retry_jitter_ms' => 0,
        'page_size' => 100,
        'datacenter_cache_ttl_seconds' => 0,
    ]);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
});

function legacyDifBackfillResource(string $handle, string $datacenter = 'ICDP'): Resource
{
    $center = Datacenter::firstOrCreate(['name' => $datacenter]);
    $resource = Resource::factory()->create([
        'doi' => '10.60510/'.strtolower($handle),
        'datacenter_id' => $center->id,
    ]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);

    return $resource;
}

/** @param array<string, string|null> $documents */
function fakeLegacyDifDocuments(array $documents): void
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

it('is dry-run first then applies all datacenter metadata additively and idempotently', function (): void {
    $resource = legacyDifBackfillResource('ICDP5052ECZI101');
    fakeLegacyDifDocuments([
        'ICDP5052ECZI101' => <<<'XML'
        <resource xmlns="http://pmd.gfz-potsdam.de/igsn/schemas/description/1.3">
          <relatedIdentifiers><relatedIdentifier type="DOI" relationType="hasDocument">10.5880/ICDP.5052.001</relatedIdentifier></relatedIdentifiers>
          <contributors><contributor type="Funder"><name>ICDP</name></contributor></contributors>
          <sample><field_name>Torlesse Greywacke</field_name><publish_date>2017-3-1</publish_date></sample>
        </resource>
        XML,
    ]);

    $dryRun = app(IgsnLegacyDifBackfillService::class)->run(datacenters: ['ICDP']);
    expect($dryRun)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'unchanged' => 0,
        'errors' => 0,
    ])->and($dryRun['records'][0]['status'])->toBe('would_update')
        ->and($resource->igsnMetadata->legacy_dif_json)->toBeNull()
        ->and(RelatedIdentifier::query()->whereBelongsTo($resource)->count())->toBe(0);

    $this->artisan('igsn:backfill-legacy-dif-metadata', [
        '--apply' => true,
        '--datacenter' => ['ICDP'],
    ])->expectsOutputToContain('DataCite full-metadata sync run:')
        ->assertSuccessful();

    expect($resource->igsnMetadata->fresh()->legacy_dif_json['aggregates']['field_names'])
        ->toBe(['Torlesse Greywacke'])
        ->and(RelatedIdentifier::query()->whereBelongsTo($resource)->sole()->relationType->slug)->toBe('Cites')
        ->and($resource->fundingReferences()->sole()->funder_name)->toBe('ICDP')
        ->and($resource->dates()->whereHas('dateType', fn ($query) => $query->where('slug', 'Available'))->sole()->date_value)
        ->toBe('2017-03-01');

    $second = app(IgsnLegacyDifBackfillService::class)->run(apply: true, datacenters: ['IGSNDB.ICDP']);
    expect($second)->toMatchArray(['changed' => 0, 'unchanged' => 1, 'errors' => 0])
        ->and($second['sync_resource_ids'])->toBe([])
        ->and(RelatedIdentifier::query()->whereBelongsTo($resource)->count())->toBe(1);
});

it('preserves curated scalar values and reports a privacy conflict for manual review', function (): void {
    $resource = legacyDifBackfillResource('ICDPPRIVACY001');
    $resource->igsnMetadata->update(['operator' => 'Curated operator', 'is_private' => false]);
    fakeLegacyDifDocuments([
        'ICDPPRIVACY001' => '<resource><sample><is_private>1</is_private><operators><operator>Legacy operator</operator></operators></sample></resource>',
    ]);

    $result = app(IgsnLegacyDifBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray(['changed' => 1, 'manual_review' => 1, 'errors' => 0])
        ->and($result['records'][0]['conflicts'])->toContain('privacy:is_private')
        ->and($resource->igsnMetadata->fresh()->is_private)->toBeFalse()
        ->and($resource->igsnMetadata->fresh()->operator)->toBe('Curated operator')
        ->and($resource->igsnMetadata->fresh()->legacy_dif_json['aggregates']['operators'])->toBe(['Legacy operator']);
});

it('reports missing DIF and invalid filters without mutating resources', function (): void {
    $resource = legacyDifBackfillResource('GFMISSINGDIF', Datacenter::GFZ_NAME);
    fakeLegacyDifDocuments(['GFMISSINGDIF' => null]);

    $result = app(IgsnLegacyDifBackfillService::class)->run(apply: true, datacenters: ['GFZ']);
    expect($result)->toMatchArray(['scanned' => 1, 'missing_dif' => 1, 'changed' => 0, 'errors' => 0])
        ->and($resource->igsnMetadata->fresh()->legacy_dif_json)->toBeNull();

    $this->artisan('igsn:backfill-legacy-dif-metadata', ['--datacenter' => ['UNKNOWN']])
        ->expectsOutputToContain('Unknown legacy IGSN datacenter')
        ->assertExitCode(2);
});

it('automatically dispatches a full metadata DataCite batch after apply', function (): void {
    Bus::fake();
    Config::set('datacite.test_mode', false);
    legacyDifBackfillResource('ICDPSYNC001');
    fakeLegacyDifDocuments([
        'ICDPSYNC001' => '<resource><sample><field_name>Rock type</field_name></sample></resource>',
    ]);

    $this->artisan('igsn:backfill-legacy-dif-metadata', ['--apply' => true])->assertSuccessful();

    Bus::assertBatched(function (PendingBatch $batch): bool {
        $job = collect($batch->jobs)->first();

        return $job instanceof SyncImportedResourcesWithDataCiteJob;
    });
});

it('queues DataCite synchronization only for changed registered IGSNs', function (): void {
    $registered = legacyDifBackfillResource('ICDPSYNC002');
    $draft = legacyDifBackfillResource('ICDPSYNC003');
    $draft->igsnMetadata->update(['upload_status' => IgsnMetadata::STATUS_PENDING]);
    fakeLegacyDifDocuments([
        'ICDPSYNC002' => '<resource><sample><field_name>Registered rock</field_name></sample></resource>',
        'ICDPSYNC003' => '<resource><sample><field_name>Draft rock</field_name></sample></resource>',
    ]);

    $result = app(IgsnLegacyDifBackfillService::class)->run(apply: true);

    expect($result['changed'])->toBe(2)
        ->and($result['sync_resource_ids'])->toBe([$registered->id])
        ->and($result['records'][0]['datacite_sync_status'])->toBe('pending')
        ->and($result['records'][1]['datacite_sync_status'])->toBe('not_queued')
        ->and($draft->igsnMetadata->fresh()->legacy_dif_json)->not->toBeNull();
});
