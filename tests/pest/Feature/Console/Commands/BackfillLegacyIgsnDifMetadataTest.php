<?php

declare(strict_types=1);

use App\Console\Commands\BackfillLegacyIgsnDifMetadata;
use App\Jobs\SyncImportedResourcesWithDataCiteJob;
use App\Models\ContributorType;
use App\Models\Datacenter;
use App\Models\IdentifierType;
use App\Models\IgsnMetadata;
use App\Models\IgsnMetadataValue;
use App\Models\IgsnOperator;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
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
        ->and(json_decode((string) $dryRun['records'][0]['existing_values'], true, flags: JSON_THROW_ON_ERROR))->toHaveKey('scalars')
        ->and(json_decode((string) $dryRun['records'][0]['source_values'], true, flags: JSON_THROW_ON_ERROR))
        ->toHaveKeys(['scalars', 'funding_agencies', 'root_related_identifiers'])
        ->and($resource->igsnMetadataValues()->count())->toBe(0)
        ->and(RelatedIdentifier::query()->whereBelongsTo($resource)->count())->toBe(0);

    $this->artisan('igsn:backfill-legacy-dif-metadata', [
        '--apply' => true,
        '--datacenter' => ['ICDP'],
    ])->expectsOutputToContain('DataCite full-metadata sync run:')
        ->assertSuccessful();

    expect(IgsnMetadataValue::query()->whereBelongsTo($resource)->sole()->value)
        ->toBe('Torlesse Greywacke')
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
        ->and(IgsnOperator::query()->whereBelongsTo($resource)->orderBy('position')->pluck('value')->all())
        ->toBe(['Curated operator', 'Legacy operator']);
});

it('reports missing DIF and invalid filters without mutating resources', function (): void {
    $resource = legacyDifBackfillResource('GFMISSINGDIF', Datacenter::GFZ_NAME);
    fakeLegacyDifDocuments(['GFMISSINGDIF' => null]);

    $result = app(IgsnLegacyDifBackfillService::class)->run(apply: true, datacenters: ['GFZ']);
    expect($result)->toMatchArray(['scanned' => 1, 'missing_dif' => 1, 'changed' => 0, 'errors' => 0])
        ->and($resource->igsnMetadataValues()->count())->toBe(0);

    $this->artisan('igsn:backfill-legacy-dif-metadata', ['--datacenter' => ['UNKNOWN']])
        ->expectsOutputToContain('Unknown legacy IGSN datacenter')
        ->assertExitCode(2);
});

it('isolates invalid DIF records without rolling back valid records', function (): void {
    $valid = legacyDifBackfillResource('ICDPVALIDDIF');
    legacyDifBackfillResource('ICDPINVALIDDIF');
    fakeLegacyDifDocuments([
        'ICDPVALIDDIF' => '<resource><sample><field_name>Valid rock</field_name></sample></resource>',
        'ICDPINVALIDDIF' => '<resource><sample>',
    ]);

    $partial = app(IgsnLegacyDifBackfillService::class)->run(apply: true);

    expect($partial)->toMatchArray([
        'scanned' => 2,
        'changed' => 1,
        'invalid_dif' => 1,
        'portal_errors' => 0,
        'errors' => 0,
    ])->and($valid->igsnMetadataValues()->sole()->value)->toBe('Valid rock');
});

it('reports a failed portal batch for every selected resource', function (): void {
    legacyDifBackfillResource('ICDPPORTALFAIL1');
    legacyDifBackfillResource('ICDPPORTALFAIL2');
    Http::fake(['igsn-portal.example.test/*' => Http::response([], 503)]);
    $portalFailure = app(IgsnLegacyDifBackfillService::class)->run(dois: [
        'ICDPPORTALFAIL1',
        'ICDPPORTALFAIL2',
    ]);

    expect($portalFailure)->toMatchArray([
        'scanned' => 2,
        'changed' => 0,
        'portal_errors' => 2,
        'errors' => 2,
    ])->and(collect($portalFailure['records'])->pluck('status')->unique()->all())->toBe(['error']);
});

it('honors resume limit and DOI filters using resource ids', function (): void {
    $first = legacyDifBackfillResource('ICDPFILTER001');
    $second = legacyDifBackfillResource('ICDPFILTER002');
    $third = legacyDifBackfillResource('ICDPFILTER003');
    fakeLegacyDifDocuments([
        'ICDPFILTER001' => '<resource><sample><field_name>First</field_name></sample></resource>',
        'ICDPFILTER002' => '<resource><sample><field_name>Second</field_name></sample></resource>',
        'ICDPFILTER003' => '<resource><sample><field_name>Third</field_name></sample></resource>',
    ]);

    $resumed = app(IgsnLegacyDifBackfillService::class)->run(afterId: $first->id, limit: 1, chunk: 500);
    $filtered = app(IgsnLegacyDifBackfillService::class)->run(dois: ['ICDPFILTER003']);

    expect($resumed)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'last_scanned_resource_id' => $second->id,
    ])->and($resumed['records'][0]['resource_id'])->toBe($second->id)
        ->and($filtered)->toMatchArray(['scanned' => 1, 'changed' => 1])
        ->and($filtered['records'][0]['resource_id'])->toBe($third->id);
});

it('keeps applied metadata and reports a false cache invalidation result', function (): void {
    $resource = legacyDifBackfillResource('ICDPCACHEFAIL');
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    fakeLegacyDifDocuments([
        'ICDPCACHEFAIL' => '<resource><sample><field_name>Cached rock</field_name></sample></resource>',
    ]);
    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldReceive('forgetById')->once()->with($landingPage->id)->andReturnFalse();
    $this->app->instance(LandingPageRenderDataCacheService::class, $cache);

    $result = app(IgsnLegacyDifBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'changed' => 1,
        'cache_invalidation_failures' => 1,
        'errors' => 0,
        'sync_resource_ids' => [$resource->id],
    ])->and($result['records'][0]['message'])->toContain('cache invalidation failed')
        ->and($resource->igsnMetadataValues()->sole()->value)->toBe('Cached rock');
});

it('writes a complete CSV audit and marks test mode synchronization as skipped', function (): void {
    legacyDifBackfillResource('ICDPREPORT001');
    fakeLegacyDifDocuments([
        'ICDPREPORT001' => '<resource><sample><field_name>Reported rock</field_name></sample></resource>',
    ]);
    $reportPath = storage_path('framework/testing/legacy-igsn-dif-backfill.csv');
    @unlink($reportPath);

    try {
        $this->artisan('igsn:backfill-legacy-dif-metadata', [
            '--apply' => true,
            '--report' => $reportPath,
        ])->assertSuccessful();

        $csv = file_get_contents($reportPath);
        expect($csv)->not->toBeFalse()
            ->and((string) $csv)->toContain('existing_values,source_values,inserted_values')
            ->and((string) $csv)->toContain('skipped_test_mode')
            ->and((string) $csv)->toContain('Reported rock');
    } finally {
        @unlink($reportPath);
    }
});

it('neutralizes spreadsheet formulas in every string CSV cell', function (): void {
    $reportPath = storage_path('framework/testing/legacy-igsn-dif-formula-audit.csv');
    @unlink($reportPath);

    try {
        $method = new ReflectionMethod(BackfillLegacyIgsnDifMetadata::class, 'writeCsv');
        $method->invoke(app(BackfillLegacyIgsnDifMetadata::class), $reportPath, [[
            'resource_id' => 1,
            'doi' => '=1+1',
            'handle' => '+1+1',
            'datacenter' => '-1+1',
            'schema_namespace' => '@SUM(1:1)',
        ]]);

        $csv = file_get_contents($reportPath);
        expect($csv)->not->toBeFalse()
            ->and((string) $csv)->toContain("'=1+1")
            ->and((string) $csv)->toContain("'+1+1")
            ->and((string) $csv)->toContain("'-1+1")
            ->and((string) $csv)->toContain("'@SUM(1:1)");
    } finally {
        @unlink($reportPath);
    }
});

it('fails cleanly for invalid sync retry ids and unwritable report targets', function (): void {
    $this->artisan('igsn:backfill-legacy-dif-metadata', ['--retry-sync' => 'not-a-uuid'])
        ->expectsOutputToContain('must be a UUID')
        ->assertExitCode(2);

    $parentPath = storage_path('framework/testing/legacy-igsn-report-parent');
    @unlink($parentPath);
    file_put_contents($parentPath, 'not a directory');

    try {
        $this->artisan('igsn:backfill-legacy-dif-metadata', ['--report' => $parentPath.'/report.csv'])
            ->expectsOutputToContain('Unable to write backfill report')
            ->assertFailed();
    } finally {
        @unlink($parentPath);
    }
});

it('rejects invalid numeric options before an apply backfill can run', function (string $option, string $value, string $message): void {
    $resource = legacyDifBackfillResource('ICDPINVALIDOPTION');
    fakeLegacyDifDocuments([
        'ICDPINVALIDOPTION' => '<resource><sample><field_name>Must not be persisted</field_name></sample></resource>',
    ]);

    $this->artisan('igsn:backfill-legacy-dif-metadata', [
        '--apply' => true,
        $option => $value,
    ])->expectsOutputToContain($message)
        ->assertExitCode(2);

    expect($resource->igsnMetadataValues()->count())->toBe(0);
})->with([
    'non-numeric limit' => ['--limit', 'abc', '--limit option must be a non-negative integer'],
    'negative after-id' => ['--after-id', '-1', '--after-id option must be a non-negative integer'],
    'zero chunk' => ['--chunk', '0', '--chunk option must be an integer from 1 to 100'],
    'oversized chunk' => ['--chunk', '101', '--chunk option must be an integer from 1 to 100'],
]);

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
        ->and($draft->igsnMetadataValues()->count())->toBe(1);
});

it('ignores non-DOI and malformed root documents on every rerun', function (): void {
    $resource = legacyDifBackfillResource('ICDPINVALIDDOCS');
    fakeLegacyDifDocuments([
        'ICDPINVALIDDOCS' => <<<'XML'
        <resource>
          <relatedIdentifiers>
            <relatedIdentifier type="URL" relationType="hasDocument">https://example.org/document</relatedIdentifier>
            <relatedIdentifier type="DOI" relationType="hasDocument">not-a-doi</relatedIdentifier>
            <relatedIdentifier type="DOI" relationType="references">10.5880/valid.but.ineligible</relatedIdentifier>
          </relatedIdentifiers>
          <sample><field_name>Eligibility regression fixture</field_name></sample>
        </resource>
        XML,
    ]);

    $first = app(IgsnLegacyDifBackfillService::class)->run(apply: true);
    $second = app(IgsnLegacyDifBackfillService::class)->run(apply: true);

    expect($first)->toMatchArray(['changed' => 1, 'errors' => 0])
        ->and($second)->toMatchArray(['changed' => 0, 'unchanged' => 1, 'errors' => 0])
        ->and($second['sync_resource_ids'])->toBe([])
        ->and(RelatedIdentifier::query()->whereBelongsTo($resource)->count())->toBe(0);
});

it('does not duplicate a Cites DOI that differs from the DIF value only by case', function (): void {
    $resource = legacyDifBackfillResource('ICDPDOICASE');
    $doiType = IdentifierType::query()->where('slug', 'DOI')->firstOrFail();
    $citesType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $existing = $resource->relatedIdentifiers()->create([
        'identifier' => '10.5880/ICDP.CaseSensitive',
        'identifier_type_id' => $doiType->id,
        'relation_type_id' => $citesType->id,
        'position' => 0,
    ]);
    fakeLegacyDifDocuments([
        'ICDPDOICASE' => <<<'XML'
        <resource>
          <relatedIdentifiers>
            <relatedIdentifier type="DOI" relationType="hasDocument">https://doi.org/10.5880/icdp.casesensitive</relatedIdentifier>
          </relatedIdentifiers>
          <sample><field_name>Case-insensitive DOI regression fixture</field_name></sample>
        </resource>
        XML,
    ]);

    $first = app(IgsnLegacyDifBackfillService::class)->run(apply: true);
    $second = app(IgsnLegacyDifBackfillService::class)->run(apply: true);

    expect($first)->toMatchArray(['changed' => 1, 'errors' => 0])
        ->and($second)->toMatchArray(['changed' => 0, 'unchanged' => 1, 'errors' => 0])
        ->and($resource->relatedIdentifiers()->count())->toBe(1)
        ->and($resource->relatedIdentifiers()->sole()->id)->toBe($existing->id);
});

it('upgrades a value-matching Local identifier to a Local accession number', function (): void {
    $resource = legacyDifBackfillResource('ICDPALTIDTYPE');
    $identifier = $resource->alternateIdentifiers()->create([
        'value' => 'Legacy sample name',
        'type' => 'Local',
        'position' => 0,
    ]);
    fakeLegacyDifDocuments([
        'ICDPALTIDTYPE' => '<resource><sample><name>Legacy sample name</name></sample></resource>',
    ]);

    $dryRun = app(IgsnLegacyDifBackfillService::class)->run();
    expect($dryRun)->toMatchArray(['changed' => 1, 'unchanged' => 0])
        ->and($dryRun['records'][0]['changed_fields'])->toContain('alternate_identifiers.name');

    $apply = app(IgsnLegacyDifBackfillService::class)->run(apply: true);
    $secondDryRun = app(IgsnLegacyDifBackfillService::class)->run();

    expect($apply)->toMatchArray(['changed' => 1, 'errors' => 0])
        ->and($identifier->fresh()->type)->toBe('Local accession number')
        ->and($resource->alternateIdentifiers()->count())->toBe(1)
        ->and($secondDryRun)->toMatchArray(['changed' => 0, 'unchanged' => 1]);
});

it('backfills missing collector and project leader details on existing contributors', function (): void {
    $resource = legacyDifBackfillResource('ICDPCONTRIBUTORDETAILS');
    $collectorType = ContributorType::query()->where('slug', 'DataCollector')->firstOrFail();
    $leaderType = ContributorType::query()->where('slug', 'ProjectLeader')->firstOrFail();

    $collector = Person::create(['family_name' => 'Roe', 'given_name' => 'Richard']);
    $collectorRelation = $resource->contributors()->create([
        'contributorable_type' => Person::class,
        'contributorable_id' => $collector->id,
        'position' => 0,
    ]);
    $collectorRelation->contributorTypes()->attach($collectorType);

    $leader = Person::create(['family_name' => 'Doe', 'given_name' => 'Jane']);
    $leaderRelation = $resource->contributors()->create([
        'contributorable_type' => Person::class,
        'contributorable_id' => $leader->id,
        'position' => 1,
    ]);
    $leaderRelation->contributorTypes()->attach($leaderType);

    fakeLegacyDifDocuments([
        'ICDPCONTRIBUTORDETAILS' => <<<'XML'
        <resource>
          <contributors>
            <contributor contributorType="ProjectLeader">
              <name>Doe, Jane</name>
              <affiliation><name>GFZ Potsdam</name></affiliation>
              <identifier>https://orcid.org/0000-0002-1825-0097</identifier>
            </contributor>
          </contributors>
          <sample><collector>Roe, Richard</collector><collector_detail>ICDP Operations</collector_detail></sample>
        </resource>
        XML,
    ]);

    $dryRun = app(IgsnLegacyDifBackfillService::class)->run();
    expect($dryRun)->toMatchArray(['changed' => 1, 'unchanged' => 0])
        ->and($dryRun['records'][0]['changed_fields'])
        ->toContain('contributors.DataCollector.affiliations')
        ->toContain('contributors.ProjectLeader.affiliations')
        ->toContain('contributors.ProjectLeader.orcid');

    $apply = app(IgsnLegacyDifBackfillService::class)->run(apply: true);
    $secondDryRun = app(IgsnLegacyDifBackfillService::class)->run();

    expect($apply)->toMatchArray(['changed' => 1, 'errors' => 0])
        ->and($collectorRelation->affiliations()->sole()->name)->toBe('ICDP Operations')
        ->and($leaderRelation->affiliations()->sole()->name)->toBe('GFZ Potsdam')
        ->and($leader->fresh()->name_identifier)->toBe('https://orcid.org/0000-0002-1825-0097')
        ->and($secondDryRun)->toMatchArray(['changed' => 0, 'unchanged' => 1]);
});

it('fills the same derived place detected by the dry run', function (): void {
    $resource = legacyDifBackfillResource('ICDPLOCATIONFALLBACK');
    $resource->geoLocations()->create([
        'country' => 'Germany',
        'province' => 'Brandenburg',
        'city' => 'Potsdam',
    ]);
    fakeLegacyDifDocuments([
        'ICDPLOCATIONFALLBACK' => <<<'XML'
        <resource><sample><country>Germany</country><province>Brandenburg</province><city>Potsdam</city></sample></resource>
        XML,
    ]);

    $dryRun = app(IgsnLegacyDifBackfillService::class)->run();
    expect($dryRun)->toMatchArray(['changed' => 1, 'unchanged' => 0])
        ->and($dryRun['records'][0]['changed_fields'])->toContain('geo_locations.place');

    app(IgsnLegacyDifBackfillService::class)->run(apply: true);
    $secondDryRun = app(IgsnLegacyDifBackfillService::class)->run();

    expect($resource->geoLocations()->sole()->place)->toBe('Potsdam, Brandenburg, Germany')
        ->and($secondDryRun)->toMatchArray(['changed' => 0, 'unchanged' => 1]);
});

it('backfills the complete approved projection and is idempotent when it is the only missing data', function (): void {
    $resource = legacyDifBackfillResource('ICDPCOMPLETE001');
    fakeLegacyDifDocuments([
        'ICDPCOMPLETE001' => <<<'XML'
        <resource>
          <relatedIdentifiers><relatedIdentifier type="DOI" relationType="hasDocument">10.5880/example.document</relatedIdentifier></relatedIdentifiers>
          <contributors>
            <contributor type="ProjectLeader"><name>Doe, Jane</name></contributor>
          </contributors>
          <sample publishdate="2020-1-2">
            <name>Sample A</name><sample_other_names><sample_other_name>Alias A</sample_other_name></sample_other_names>
            <sample_type>Core</sample_type><material>Rock</material><user_code>Project A</user_code>
            <cruise_field_program>Cruise A</cruise_field_program><sample_purpose>Research</sample_purpose>
            <depth_min>1</depth_min><depth_max>2</depth_max><depth_scale>m</depth_scale>
            <collection_method>Drilling</collection_method><collection_method_description>Rotary</collection_method_description>
            <collection_date_precision>day</collection_date_precision>
            <collection_start_date>2020-01-01</collection_start_date><collection_end_date>2020-01-03</collection_end_date>
            <sampling_date>2020-01-02T10:00:00Z</sampling_date><collector>Roe, Richard</collector><collector_detail>GFZ</collector_detail>
            <platform_type>Rig</platform_type><platform_name>Rig A</platform_name><platform_description>Unit A</platform_description>
            <current_archive>Archive A</current_archive><current_archive_contact>archive@example.org</current_archive_contact>
            <original_archive>Archive B</original_archive><original_archive_contact>original@example.org</original_archive_contact>
            <sample_access>Public</sample_access><coordinate_system>WGS84</coordinate_system>
            <latitude>52.0</latitude><longitude>13.0</longitude><elevation>100</elevation><elevation_end>110</elevation_end>
            <elevation_unit>m</elevation_unit><elevation_end_unit>m</elevation_end_unit>
            <primary_location_name>Potsdam</primary_location_name><country>Germany</country><city>Potsdam</city>
            <descriptions><description descriptionScheme="Lithology">Granite</description></descriptions><comment>Comment A</comment>
            <classification>plutonic rock and plutonite</classification><geological_age>Jurassic</geological_age><geological_unit>Unit A</geological_unit>
            <size>50</size><size_unit>diameter [mm]</size_unit>
            <operators><operator>Operator A</operator><operator>Operator B</operator></operators>
            <funding_agency>Agency A, Program B</funding_agency><field_name>Granite</field_name>
            <classification_comment>Reviewed</classification_comment><sample_request>Request A</sample_request><sampled_by>Requester A</sampled_by>
            <methods><method methodScheme="XRF">Method A</method></methods><length>25.5</length><length_unit>m</length_unit>
            <age_min>10</age_min><age_max>20</age_max><age_unit>Ma</age_unit>
            <launch_platform_name>SO-273</launch_platform_name><launch_type_name>Corer</launch_type_name><navigation_type>GPS</navigation_type>
            <sample_image>CS_5054.jpg</sample_image><sample_image_path>http://www-icdp.icdp-online.org/sites/cosc/news/cores/</sample_image_path>
          </sample>
        </resource>
        XML,
    ]);

    $dryRun = app(IgsnLegacyDifBackfillService::class)->run();
    $apply = app(IgsnLegacyDifBackfillService::class)->run(apply: true);
    $secondDryRun = app(IgsnLegacyDifBackfillService::class)->run();

    expect($dryRun)->toMatchArray(['changed' => 1, 'errors' => 0])
        ->and($apply)->toMatchArray(['changed' => 1, 'errors' => 0])
        ->and($secondDryRun['records'][0]['changed_fields'])->toBe('')
        ->and($secondDryRun)->toMatchArray(['changed' => 0, 'unchanged' => 1, 'errors' => 0])
        ->and($resource->igsnOperators()->count())->toBe(2)
        ->and($resource->igsnMethods()->count())->toBe(1)
        ->and($resource->igsnMeasurements()->count())->toBe(3)
        ->and($resource->igsnMetadataValues()->count())->toBe(7)
        ->and($resource->igsnGeologicalAges()->sole()->value)->toBe('Jurassic')
        ->and($resource->alternateIdentifiers()->count())->toBe(2)
        ->and($resource->geoLocations()->sole()->place)->toBe('Potsdam')
        ->and($resource->fundingReferences()->sole()->funder_name)->toBe('Agency A, Program B')
        ->and($resource->contributors()->with('contributorable')->get()->contains(
            fn ($contributor): bool => $contributor->contributorable instanceof Person,
        ))->toBeTrue()
        ->and($resource->igsnMetadata->fresh()->sample_image_external_url)
        ->toBe('https://data.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg');
});
