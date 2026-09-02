<?php

declare(strict_types=1);

use App\Console\Commands\UpgradeDataCiteSchemas;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteSchemaUpgradeService;
use App\Support\DataCiteSchemaVersion;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

covers(UpgradeDataCiteSchemas::class, DataCiteSchemaUpgradeService::class, DataCiteMemberApiClient::class);

beforeEach(function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST.REPO',
        'datacite.test.password' => 'test-secret',
        'datacite.test.client_id' => 'test.repo',
        'datacite.test.prefixes' => ['10.83279'],
        'datacite.production.endpoint' => 'https://api.datacite.org',
        'datacite.production.username' => 'TIB.GFZ',
        'datacite.production.password' => 'production-secret',
        'datacite.production.client_id' => 'tib.gfz',
        'datacite.production.prefixes' => ['10.5880'],
        'datacite.production.igsn_prefix' => '10.60510',
        'datacite.schema_upgrade.page_size' => 1000,
        'datacite.schema_upgrade.snapshot_directory' => 'datacite-schema-upgrades',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 1000,
        'datacite.landing_page_url_update.window_seconds' => 300,
    ]);

    Cache::flush();
    Storage::fake('local');
});

/**
 * @param  list<array<string, mixed>>  $contributors
 * @return array<string, mixed>
 */
function schemaUpgradeRemoteRecord(
    string $doi,
    ?string $schemaVersion = 'http://datacite.org/schema/kernel-3',
    ?string $resourceTypeGeneral = 'Dataset',
    array $contributors = [],
    string $clientId = 'test.repo',
): array {
    $attributes = [
        'doi' => $doi,
        'state' => 'findable',
        'url' => 'https://example.test/landing-page/'.$doi,
        'types' => $resourceTypeGeneral === null ? [] : [
            'resourceTypeGeneral' => $resourceTypeGeneral,
            'resourceType' => $resourceTypeGeneral,
        ],
        'contributors' => $contributors,
    ];
    if ($schemaVersion !== null) {
        $attributes['schemaVersion'] = $schemaVersion;
    }

    return [
        'id' => $doi,
        'type' => 'dois',
        'attributes' => $attributes,
        'relationships' => [
            'client' => ['data' => ['id' => $clientId, 'type' => 'clients']],
        ],
    ];
}

/** @param list<array<string, mixed>> $records */
function fakeSchemaUpgradeDoiList(array $records): void
{
    Http::fake(function (Request $request) use ($records) {
        if ($request->method() !== 'GET' || ! str_contains($request->url(), '/dois?')) {
            return Http::response(['errors' => [['detail' => 'Unexpected request']]], 500);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $pageNumber = (int) ($query['page']['number'] ?? 1);
        $pageSize = (int) ($query['page']['size'] ?? 1000);
        $pages = max(1, (int) ceil(count($records) / $pageSize));

        return Http::response([
            'data' => array_slice($records, ($pageNumber - 1) * $pageSize, $pageSize),
            'meta' => ['total' => count($records), 'totalPages' => $pages],
        ]);
    });
}

it('lists repository DOIs with bounded authenticated pagination', function (): void {
    Http::fake(['https://api.test.datacite.org/*' => Http::response([
        'data' => [],
        'meta' => ['total' => 0, 'totalPages' => 1],
    ])]);

    $response = app(DataCiteMemberApiClient::class)->listDois(pageNumber: 2, pageSize: 50);

    expect($response->successful())->toBeTrue();
    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/dois'
            && $query === [
                'client-id' => 'test.repo',
                'page' => ['number' => '2', 'size' => '50'],
            ]
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('TEST.REPO:test-secret')
            && $request->hasHeader('User-Agent');
    });
});

it('retries transient DOI-list failures through the shared transport policy', function (): void {
    Http::fake([
        'https://api.test.datacite.org/*' => Http::sequence()
            ->push(['errors' => [['detail' => 'Temporary outage']]], 503)
            ->push(['data' => [], 'meta' => ['totalPages' => 1]], 200),
    ]);

    $response = app(DataCiteMemberApiClient::class)->listDois();

    expect($response->successful())->toBeTrue();
    Http::assertSentCount(2);
});

it('rejects unsafe pagination and a missing repository client ID before sending a request', function (): void {
    Http::fake();
    $client = app(DataCiteMemberApiClient::class);

    expect(fn () => $client->listDois(0))->toThrow(InvalidArgumentException::class, 'page number')
        ->and(fn () => $client->listDois(1, 1001))->toThrow(InvalidArgumentException::class, 'page size');

    config(['datacite.test.client_id' => null]);
    $client = app(DataCiteMemberApiClient::class);
    expect(fn () => $client->listDois())->toThrow(RuntimeException::class, 'DATACITE_TEST_CLIENT_ID');
    Http::assertNothingSent();
});

it('audits every page and classifies local legacy candidates without writing', function (): void {
    config(['datacite.schema_upgrade.page_size' => 3]);
    $eligibleKernel3 = Resource::factory()->create(['doi' => '10.83279/LEGACY-THREE']);
    $eligibleMissing = Resource::factory()->create(['doi' => '10.83279/missing-schema']);
    $current = Resource::factory()->create(['doi' => '10.83279/current']);
    $unknown = Resource::factory()->create(['doi' => '10.83279/unknown']);
    $funder = Resource::factory()->create(['doi' => '10.83279/funder']);
    $missingType = Resource::factory()->create(['doi' => '10.83279/missing-type']);
    $clientMismatch = Resource::factory()->create(['doi' => '10.83279/client-mismatch']);
    $foreignPrefix = Resource::factory()->create(['doi' => '10.99999/foreign']);
    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    $igsn = Resource::factory()->create([
        'doi' => '10.60510/GFTEST001',
        'resource_type_id' => $physicalObject->id,
    ]);

    fakeSchemaUpgradeDoiList([
        schemaUpgradeRemoteRecord('10.83279/legacy-three'),
        schemaUpgradeRemoteRecord('10.83279/missing-schema', null),
        schemaUpgradeRemoteRecord('10.83279/current', DataCiteSchemaVersion::KERNEL_4),
        schemaUpgradeRemoteRecord('10.83279/unknown', 'custom-schema'),
        schemaUpgradeRemoteRecord('10.83279/funder', contributors: [['name' => 'Legacy funder', 'contributorType' => 'Funder']]),
        schemaUpgradeRemoteRecord('10.83279/missing-type', resourceTypeGeneral: null),
        schemaUpgradeRemoteRecord('10.83279/client-mismatch', clientId: 'other.repo'),
        schemaUpgradeRemoteRecord('10.99999/foreign'),
        schemaUpgradeRemoteRecord('10.60510/GFTEST001', resourceTypeGeneral: 'PhysicalObject'),
        schemaUpgradeRemoteRecord('10.83279/not-imported'),
    ]);

    $result = app(DataCiteSchemaUpgradeService::class)->run();
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray([
        'apply' => false,
        'remote_scanned' => 10,
        'selected' => 10,
        'candidates' => 2,
        'already_current' => 1,
        'would_update' => 2,
        'updated' => 0,
        'manual_review' => 4,
        'not_imported' => 1,
        'excluded' => 2,
        'errors' => 0,
    ])->and($records['10.83279/legacy-three']['resource_id'])->toBe($eligibleKernel3->id)
        ->and($records['10.83279/legacy-three']['status'])->toBe('would_update')
        ->and($records['10.83279/missing-schema']['resource_id'])->toBe($eligibleMissing->id)
        ->and($records['10.83279/current']['resource_id'])->toBe($current->id)
        ->and($records['10.83279/current']['status'])->toBe('already_current')
        ->and($records['10.83279/unknown']['resource_id'])->toBe($unknown->id)
        ->and($records['10.83279/unknown']['status'])->toBe('manual_review')
        ->and($records['10.83279/funder']['resource_id'])->toBe($funder->id)
        ->and($records['10.83279/missing-type']['resource_id'])->toBe($missingType->id)
        ->and($records['10.83279/client-mismatch']['resource_id'])->toBe($clientMismatch->id)
        ->and($records['10.99999/foreign']['resource_id'])->toBe($foreignPrefix->id)
        ->and($records['10.99999/foreign']['status'])->toBe('excluded_prefix')
        ->and($records['10.60510/gftest001']['resource_id'])->toBe($igsn->id)
        ->and($records['10.60510/gftest001']['status'])->toBe('excluded_igsn')
        ->and($records['10.83279/not-imported']['status'])->toBe('not_imported')
        ->and($result['snapshot_directory'])->toBeNull();

    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    Storage::disk('local')->assertDirectoryEmpty('/');
});

it('applies only schemaVersion and existing types and stores a private snapshot', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.83279/minimal-update']);
    $remote = schemaUpgradeRemoteRecord('10.83279/minimal-update');

    Http::fake(function (Request $request) use ($remote) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/dois?')) {
            return Http::response(['data' => [$remote], 'meta' => ['totalPages' => 1]]);
        }
        if ($request->method() === 'PUT') {
            return Http::response(['data' => [
                'id' => '10.83279/minimal-update',
                'attributes' => ['schemaVersion' => DataCiteSchemaVersion::KERNEL_4],
            ]]);
        }

        return Http::response([], 500);
    });

    $result = app(DataCiteSchemaUpgradeService::class)->run(apply: true);
    $record = collect($result['records'])->firstWhere('doi', '10.83279/minimal-update');

    expect($result)->toMatchArray([
        'candidates' => 1,
        'updated' => 1,
        'errors' => 0,
        'last_resource_id' => $resource->id,
    ])->and($record['status'])->toBe('updated')
        ->and($record['attempts'])->toBe(1)
        ->and($record['verified_at'])->not->toBeNull()
        ->and($record['snapshot_path'])->toBeString();

    Storage::disk('local')->assertExists($record['snapshot_path']);
    $snapshot = json_decode((string) Storage::disk('local')->get($record['snapshot_path']), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['data']['attributes']['url'])->toBe('https://example.test/landing-page/10.83279/minimal-update');

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'PUT') {
            return false;
        }

        return $request->data() === [
            'data' => [
                'id' => '10.83279/minimal-update',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/minimal-update',
                    'schemaVersion' => DataCiteSchemaVersion::KERNEL_4,
                    'types' => [
                        'resourceTypeGeneral' => 'Dataset',
                        'resourceType' => 'Dataset',
                    ],
                ],
            ],
        ];
    });
});

it('isolates update errors and verifies an ambiguous success with one DOI GET', function (): void {
    $failed = Resource::factory()->create(['doi' => '10.83279/fails']);
    $verified = Resource::factory()->create(['doi' => '10.83279/verifies']);
    $remoteRecords = [
        schemaUpgradeRemoteRecord('10.83279/fails'),
        schemaUpgradeRemoteRecord('10.83279/verifies', 'http://datacite.org/schema/kernel-2.2'),
    ];

    Http::fake(function (Request $request) use ($remoteRecords) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/dois?')) {
            return Http::response(['data' => $remoteRecords, 'meta' => ['totalPages' => 1]]);
        }
        if ($request->method() === 'PUT' && str_contains($request->url(), 'fails')) {
            return Http::response(['errors' => [['detail' => 'Legacy metadata is invalid']]], 422);
        }
        if ($request->method() === 'PUT' && str_contains($request->url(), 'verifies')) {
            return Http::response(['data' => ['attributes' => ['state' => 'findable']]]);
        }
        if ($request->method() === 'GET' && str_contains($request->url(), 'verifies')) {
            return Http::response(['data' => ['attributes' => [
                'schemaVersion' => DataCiteSchemaVersion::KERNEL_4,
                'state' => 'findable',
            ]]]);
        }

        return Http::response([], 500);
    });

    $result = app(DataCiteSchemaUpgradeService::class)->run(apply: true);
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray([
        'candidates' => 2,
        'updated' => 1,
        'errors' => 1,
        'last_resource_id' => $verified->id,
    ])->and($records[$failed->doi]['status'])->toBe('error')
        ->and($records[$failed->doi]['http_status'])->toBe(422)
        ->and($records[$verified->doi]['status'])->toBe('updated')
        ->and($records[$verified->doi]['attempts'])->toBe(2);

    Http::assertSentCount(4);
});

it('reports failed verification when DataCite still returns the legacy schema', function (): void {
    Resource::factory()->create(['doi' => '10.83279/not-verified']);
    $remote = schemaUpgradeRemoteRecord('10.83279/not-verified');

    Http::fake(function (Request $request) use ($remote) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/dois?')) {
            return Http::response(['data' => [$remote], 'meta' => ['totalPages' => 1]]);
        }
        if ($request->method() === 'PUT') {
            return Http::response(['data' => ['attributes' => ['state' => 'findable']]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['data' => ['attributes' => [
                'schemaVersion' => 'http://datacite.org/schema/kernel-3',
            ]]]);
        }

        return Http::response([], 500);
    });

    $result = app(DataCiteSchemaUpgradeService::class)->run(apply: true);
    $record = collect($result['records'])->firstWhere('doi', '10.83279/not-verified');

    expect($result)->toMatchArray(['updated' => 0, 'errors' => 1])
        ->and($record['status'])->toBe('verification_failed')
        ->and($record['attempts'])->toBe(2);
});

it('aborts subsequent writes after repository authentication fails', function (): void {
    $first = Resource::factory()->create(['doi' => '10.83279/auth-first']);
    $second = Resource::factory()->create(['doi' => '10.83279/auth-second']);
    $remoteRecords = [
        schemaUpgradeRemoteRecord($first->doi),
        schemaUpgradeRemoteRecord($second->doi),
    ];

    Http::fake(function (Request $request) use ($remoteRecords) {
        if ($request->method() === 'GET') {
            return Http::response(['data' => $remoteRecords, 'meta' => ['totalPages' => 1]]);
        }

        return Http::response(['errors' => [['detail' => 'Repository access denied']]], 403);
    });

    $result = app(DataCiteSchemaUpgradeService::class)->run(apply: true);
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray(['updated' => 0, 'errors' => 1, 'skipped' => 1])
        ->and($records[$first->doi]['status'])->toBe('authentication_failed')
        ->and($records[$second->doi]['status'])->toBe('not_processed');
    Http::assertSentCount(2);
});

it('supports DOI filters, resume IDs, and candidate limits', function (): void {
    $first = Resource::factory()->create(['doi' => '10.83279/first']);
    $second = Resource::factory()->create(['doi' => '10.83279/second']);
    $third = Resource::factory()->create(['doi' => '10.83279/third']);
    fakeSchemaUpgradeDoiList([
        schemaUpgradeRemoteRecord('10.83279/first'),
        schemaUpgradeRemoteRecord('10.83279/second'),
        schemaUpgradeRemoteRecord('10.83279/third'),
    ]);

    $result = app(DataCiteSchemaUpgradeService::class)->run(
        afterId: $first->id,
        limit: 1,
        dois: ['10.83279/first', 'https://doi.org/10.83279/SECOND', '10.83279/third'],
    );
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray([
        'selected' => 3,
        'candidates' => 2,
        'would_update' => 1,
        'skipped' => 2,
    ])->and($records[$first->doi]['status'])->toBe('skipped_before_id')
        ->and($records[$second->doi]['status'])->toBe('would_update')
        ->and($records[$third->doi]['status'])->toBe('skipped_limit');
});

it('is idempotent when DataCite already reports Kernel 4', function (): void {
    Resource::factory()->create(['doi' => '10.83279/already-upgraded']);
    fakeSchemaUpgradeDoiList([
        schemaUpgradeRemoteRecord('10.83279/already-upgraded', DataCiteSchemaVersion::KERNEL_4),
    ]);

    $result = app(DataCiteSchemaUpgradeService::class)->run(dois: ['10.83279/already-upgraded']);

    expect($result)->toMatchArray([
        'selected' => 1,
        'candidates' => 0,
        'already_current' => 1,
        'would_update' => 0,
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

it('reports requested DOIs that are absent from the configured repository', function (): void {
    fakeSchemaUpgradeDoiList([]);

    $result = app(DataCiteSchemaUpgradeService::class)->run(dois: ['10.83279/not-found']);

    expect($result)->toMatchArray(['selected' => 0, 'errors' => 1])
        ->and($result['records'][0]['doi'])->toBe('10.83279/not-found')
        ->and($result['records'][0]['status'])->toBe('error');
});

it('requires an explicit production-write acknowledgement before making API requests', function (): void {
    config(['datacite.test_mode' => false]);
    Http::fake();

    $this->artisan('resources:upgrade-datacite-schema --apply')
        ->expectsOutput('Production writes require both --apply and --force-production.')
        ->assertExitCode(Command::INVALID);

    Http::assertNothingSent();
});

it('writes a spreadsheet-safe report and fails when manual review remains', function (): void {
    $reportPath = storage_path('app/datacite-schema-upgrade-test.csv');
    File::delete($reportPath);
    Resource::factory()->create(['doi' => '10.83279/report']);
    fakeSchemaUpgradeDoiList([
        schemaUpgradeRemoteRecord('10.83279/report', resourceTypeGeneral: '=FORMULA'),
    ]);

    $this->artisan('resources:upgrade-datacite-schema', ['--report' => $reportPath])
        ->expectsOutput('Dry run only; no DataCite records were changed.')
        ->expectsOutput('Some DataCite records need manual review; inspect the CSV report before retrying them.')
        ->assertExitCode(Command::FAILURE);

    $report = File::get($reportPath);
    expect($report)->toContain("'=FORMULA")
        ->and($report)->toContain('manual_review');
    File::delete($reportPath);
});

it('rejects invalid command options and DOI filters', function (): void {
    $this->artisan('resources:upgrade-datacite-schema', ['--limit' => '-1'])
        ->expectsOutput('The --limit option must be a non-negative integer.')
        ->assertExitCode(Command::INVALID);

    $this->artisan('resources:upgrade-datacite-schema', ['--doi' => ['not-a-doi']])
        ->expectsOutput('Invalid DOI filter: not-a-doi')
        ->assertExitCode(Command::INVALID);
});
