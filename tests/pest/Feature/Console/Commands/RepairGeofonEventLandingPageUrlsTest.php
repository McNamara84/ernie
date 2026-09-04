<?php

declare(strict_types=1);

use App\Console\Commands\RepairGeofonEventLandingPageUrls;
use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use App\Services\DataCiteMemberApiClient;
use App\Services\GeofonEventLandingPageUrlRepairService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class GeofonFailingCsvStreamWrapper
{
    public mixed $context;

    public static int $successfulWrites = 0;

    private int $writes = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->writes = 0;

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->writes >= self::$successfulWrites) {
            return 0;
        }

        $this->writes++;

        return strlen($data);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

covers(
    RepairGeofonEventLandingPageUrls::class,
    GeofonEventLandingPageUrlRepairService::class,
    DataCiteMemberApiClient::class,
);

beforeEach(function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST.REPO',
        'datacite.test.password' => 'test-secret',
        'datacite.test.client_id' => 'test.repo',
        'datacite.test.prefixes' => ['10.1594', '10.5880'],
        'datacite.production.endpoint' => 'https://api.datacite.org',
        'datacite.production.username' => 'TIB.GFZ',
        'datacite.production.password' => 'production-secret',
        'datacite.production.client_id' => 'tib.gfz',
        'datacite.production.prefixes' => ['10.1594', '10.5880'],
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 1000,
        'datacite.landing_page_url_update.window_seconds' => 300,
        'datacite.geofon_event_url_repair.snapshot_directory' => 'geofon-event-url-updates-test',
    ]);

    Cache::flush();
    Storage::fake('local');
});

function geofonRepairResource(
    string $doi,
    string $path,
    string $datacenter = LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
    string $domain = 'https://geofon.gfz.de/',
): Resource {
    $datacenterModel = Datacenter::query()->firstOrCreate(['name' => $datacenter]);
    $domainModel = LandingPageDomain::query()->firstOrCreate(['domain' => $domain]);
    $resource = Resource::factory()->create([
        'doi' => $doi,
        'datacenter_id' => $datacenterModel->id,
    ]);
    LandingPage::factory()
        ->for($resource)
        ->external()
        ->published()
        ->create([
            'doi_prefix' => $doi,
            'external_domain_id' => $domainModel->id,
            'external_path' => $path,
        ]);

    return $resource->fresh(['datacenter', 'landingPage.externalDomain']);
}

/** @return array<string, mixed> */
function geofonRepairRemoteRecord(
    string $doi,
    string $url,
    string $client = 'test.repo',
    string $state = 'findable',
): array {
    return [
        'id' => $doi,
        'type' => 'dois',
        'attributes' => [
            'doi' => $doi,
            'url' => $url,
            'state' => $state,
        ],
        'relationships' => [
            'client' => ['data' => ['id' => $client, 'type' => 'clients']],
        ],
    ];
}

/** @param array<string, string> $remoteUrls */
function fakeGeofonRepairApi(array &$remoteUrls): void
{
    Http::fake(function (Request $request) use (&$remoteUrls) {
        if (parse_url($request->url(), PHP_URL_HOST) === 'geofon.gfz.de') {
            return Http::response('', 200);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $doi = rawurldecode(substr($path, strlen('/dois/')));
        if (! isset($remoteUrls[$doi])) {
            return Http::response(['errors' => [['detail' => 'DOI not found']]], 404);
        }

        if ($request->method() === 'PUT') {
            $target = $request->data()['data']['attributes']['url'] ?? null;
            if (! is_string($target)) {
                return Http::response(['errors' => [['detail' => 'Missing URL']]], 422);
            }
            $remoteUrls[$doi] = $target;
        }

        return Http::response(['data' => geofonRepairRemoteRecord($doi, $remoteUrls[$doi])]);
    });
}

it('performs a complete read-only dry run for stale local and DataCite URLs', function (): void {
    $doi = '10.1594/gfz.geofon.gfz2011axdw';
    $resource = geofonRepairResource($doi, 'db/eqpage.php?id=gfz2011axdw');
    $remoteUrls = [$doi => 'http://geofon.gfz.de/db/eqpage.php?id=gfz2011axdw'];
    fakeGeofonRepairApi($remoteUrls);

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run();
    $record = $result['records'][0];

    expect($result)->toMatchArray([
        'apply' => false,
        'resources_scanned' => 1,
        'candidates' => 1,
        'would_update' => 1,
        'updated' => 0,
        'errors' => 0,
    ])->and($record)->toMatchArray([
        'resource_id' => $resource->id,
        'doi' => $doi,
        'event_id' => 'gfz2011axdw',
        'local_status' => 'would_update',
        'datacite_status' => 'would_update',
        'overall_status' => 'would_update_both',
        'target_http_status' => 200,
    ])->and($resource->landingPage->fresh()->external_path)
        ->toBe('db/eqpage.php?id=gfz2011axdw')
        ->and($remoteUrls[$doi])->toBe('http://geofon.gfz.de/db/eqpage.php?id=gfz2011axdw')
        ->and($result['snapshot_directory'])->toBeNull();

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    Storage::disk('local')->assertDirectoryEmpty('/');
});

it('updates DataCite first, verifies it, then updates ERNIE and stores a snapshot', function (): void {
    $doi = '10.1594/gfz.geofon.gfz2010gtdx';
    $resource = geofonRepairResource(
        $doi,
        'db/eqpage.php?id=gfz2010gtdx',
        domain: 'http://geofon.gfz-potsdam.de/',
    );
    $remoteUrls = [$doi => 'https://geofon.gfz.de/db/eqpage.php?id=gfz2010gtdx'];
    fakeGeofonRepairApi($remoteUrls);

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $record = $result['records'][0];
    $landingPage = $resource->fresh(['landingPage.externalDomain'])->landingPage;

    expect($result)->toMatchArray([
        'updated' => 1,
        'local_updated' => 1,
        'datacite_updated' => 1,
        'errors' => 0,
        'last_resource_id' => $resource->id,
    ])->and($record['overall_status'])->toBe('updated_both')
        ->and($record['snapshot_path'])->toBeString()
        ->and($record['update_http_status'])->toBe(200)
        ->and($landingPage->externalDomain->domain)->toBe('https://geofon.gfz.de/')
        ->and($landingPage->external_path)->toBe('eqinfo/event.php?id=gfz2010gtdx')
        ->and($remoteUrls[$doi])->toBe('https://geofon.gfz.de/eqinfo/event.php?id=gfz2010gtdx');

    Storage::disk('local')->assertExists($record['snapshot_path']);
    $snapshot = json_decode(
        (string) Storage::disk('local')->get($record['snapshot_path']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($snapshot['data']['attributes']['url'])
        ->toBe('https://geofon.gfz.de/db/eqpage.php?id=gfz2010gtdx');

    $methods = Http::recorded()->map(fn (array $pair): string => $pair[0]->method())->all();
    expect($methods)->toBe(['GET', 'HEAD', 'PUT', 'GET']);
    Http::assertSent(function (Request $request) use ($doi): bool {
        return $request->method() === 'PUT'
            && rawurldecode((string) parse_url($request->url(), PHP_URL_PATH)) === '/dois/'.$doi
            && $request->data() === [
                'data' => [
                    'id' => $doi,
                    'type' => 'dois',
                    'attributes' => [
                        'url' => 'https://geofon.gfz.de/eqinfo/event.php?id=gfz2010gtdx',
                    ],
                ],
            ];
    });
});

it('repairs either ERNIE or DataCite independently and is idempotent', function (): void {
    $localOnlyDoi = '10.1594/gfz.geofon.gfz2009gibb';
    $remoteOnlyDoi = '10.5880/geofon.gfz2015icra';
    $currentDoi = '10.1594/gfz.geofon.gfz2008ewsv';
    geofonRepairResource($localOnlyDoi, 'db/eqpage.php?id=gfz2009gibb');
    geofonRepairResource($remoteOnlyDoi, 'eqinfo/event.php?id=gfz2015icra');
    geofonRepairResource($currentDoi, 'eqinfo/event.php?id=gfz2008ewsv');
    $remoteUrls = [
        $localOnlyDoi => 'https://geofon.gfz.de/eqinfo/event.php?id=gfz2009gibb',
        $remoteOnlyDoi => 'http://geofon.gfz.de/db/eqpage.php?id=gfz2015icra',
        $currentDoi => 'https://geofon.gfz.de/eqinfo/event.php?id=gfz2008ewsv',
    ];
    fakeGeofonRepairApi($remoteUrls);

    $applied = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $records = collect($applied['records'])->keyBy('doi');

    expect($applied)->toMatchArray([
        'candidates' => 3,
        'already_current' => 1,
        'updated' => 2,
        'local_updated' => 1,
        'datacite_updated' => 1,
        'errors' => 0,
    ])->and($records[$localOnlyDoi]['overall_status'])->toBe('updated_local')
        ->and($records[$remoteOnlyDoi]['overall_status'])->toBe('updated_datacite')
        ->and($records[$currentDoi]['overall_status'])->toBe('already_current');

    Http::assertSentCount(7);
    $putCount = Http::recorded()->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT')->count();
    expect($putCount)->toBe(1);

    fakeGeofonRepairApi($remoteUrls);
    $rerun = app(GeofonEventLandingPageUrlRepairService::class)->run();

    expect($rerun)->toMatchArray([
        'candidates' => 3,
        'already_current' => 3,
        'would_update' => 0,
        'errors' => 0,
    ]);
});

it('reports a legacy GEOFON event URL outside the event datacenter without any request', function (): void {
    $resource = geofonRepairResource(
        '10.1594/gfz.geofon.gfz2009givj',
        'db/eqpage.php?id=gfz2009givj',
        datacenter: 'Unexpected Datacenter',
    );
    Http::fake();

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'resources_scanned' => 1,
        'candidates' => 0,
        'wrong_datacenter' => 1,
        'manual_review' => 1,
        'errors' => 0,
    ])->and($result['records'][0]['overall_status'])->toBe('manual_review_wrong_datacenter')
        ->and($resource->landingPage->fresh()->external_path)->toBe('db/eqpage.php?id=gfz2009givj');
    Http::assertNothingSent();
});

it('requires manual review for invalid or non-GEOFON URLs in the event datacenter', function (): void {
    $foreignHost = geofonRepairResource(
        '10.1594/gfz.geofon.gfz2009groy',
        'db/eqpage.php?id=gfz2009groy',
        domain: 'https://example.org/',
    );
    $emptyUrl = geofonRepairResource(
        '10.1594/gfz.geofon.gfz2009kciu',
        'db/eqpage.php?id=gfz2009kciu',
    );
    $emptyUrl->landingPage->update([
        'external_domain_id' => null,
        'external_path' => null,
    ]);
    Http::fake();

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray([
        'resources_scanned' => 2,
        'candidates' => 0,
        'manual_review' => 2,
        'skipped' => 0,
        'errors' => 0,
    ])->and($records[$foreignHost->doi])->toMatchArray([
        'local_status' => 'manual_review',
        'overall_status' => 'manual_review_local_url',
        'message' => 'The landing-page host example.org is not an allowed GEOFON host.',
    ])->and($records[$emptyUrl->doi])->toMatchArray([
        'local_before_url' => '',
        'local_status' => 'manual_review',
        'overall_status' => 'manual_review_local_url',
        'message' => 'The landing-page URL is empty.',
    ]);

    $this->artisan('resources:repair-geofon-event-landing-page-urls', [
        '--doi' => [$foreignHost->doi],
    ])->expectsOutput('Some records need manual review and were not changed; inspect the CSV report.')
        ->assertExitCode(Command::FAILURE);
    Http::assertNothingSent();
});

it('leaves local and remote URLs unchanged for event ID and URL conflicts', function (): void {
    $localMismatchDoi = '10.1594/gfz.geofon.gfz2009groy';
    $remoteMismatchDoi = '10.1594/gfz.geofon.gfz2009kciu';
    $unknownRemoteDoi = '10.1594/gfz.geofon.gfz2010dzva';
    geofonRepairResource($localMismatchDoi, 'db/eqpage.php?id=gfz2009xxxx');
    geofonRepairResource($remoteMismatchDoi, 'db/eqpage.php?id=gfz2009kciu');
    geofonRepairResource($unknownRemoteDoi, 'db/eqpage.php?id=gfz2010dzva');
    $remoteUrls = [
        $localMismatchDoi => 'http://geofon.gfz.de/db/eqpage.php?id=gfz2009groy',
        $remoteMismatchDoi => 'http://geofon.gfz.de/db/eqpage.php?id=gfz2009xxxx',
        $unknownRemoteDoi => 'https://geofon.gfz.de/custom/event?id=gfz2010dzva',
    ];
    fakeGeofonRepairApi($remoteUrls);

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray(['manual_review' => 3, 'updated' => 0, 'errors' => 0])
        ->and($records[$localMismatchDoi]['overall_status'])->toBe('manual_review_event_id_mismatch')
        ->and($records[$remoteMismatchDoi]['overall_status'])->toBe('manual_review_event_id_mismatch')
        ->and($records[$unknownRemoteDoi]['overall_status'])->toBe('manual_review_datacite_url');
    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['HEAD', 'PUT'], true));
});

it('blocks both writes when the canonical GEOFON target is unavailable', function (): void {
    $doi = '10.1594/gfz.geofon.gfz2011ewla';
    $resource = geofonRepairResource($doi, 'db/eqpage.php?id=gfz2011ewla');
    Http::fake(function (Request $request) use ($doi) {
        if (parse_url($request->url(), PHP_URL_HOST) === 'geofon.gfz.de') {
            return Http::response('', 503);
        }

        return Http::response(['data' => geofonRepairRemoteRecord(
            $doi,
            'http://geofon.gfz.de/db/eqpage.php?id=gfz2011ewla',
        )]);
    });

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);

    expect($result)->toMatchArray(['target_unreachable' => 1, 'updated' => 0, 'errors' => 1])
        ->and($result['records'][0])->toMatchArray([
            'local_status' => 'blocked',
            'datacite_status' => 'blocked',
            'overall_status' => 'target_unreachable',
            'target_http_status' => 503,
        ])->and($resource->landingPage->fresh()->external_path)->toBe('db/eqpage.php?id=gfz2011ewla');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

it('isolates DataCite lookup and ownership failures without probing or writing', function (): void {
    $missingDoi = '10.1594/gfz.geofon.gfz2008ewsv';
    $clientDoi = '10.1594/gfz.geofon.gfz2009givj';
    $remoteDoi = '10.1594/gfz.geofon.gfz2010dzva';
    geofonRepairResource($missingDoi, 'db/eqpage.php?id=gfz2008ewsv');
    geofonRepairResource($clientDoi, 'db/eqpage.php?id=gfz2009givj');
    geofonRepairResource($remoteDoi, 'db/eqpage.php?id=gfz2010dzva');
    Http::fake(function (Request $request) use ($missingDoi, $clientDoi, $remoteDoi) {
        $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
        if ($path === '/dois/'.$missingDoi) {
            return Http::response(['errors' => [['detail' => 'Not registered']]], 404);
        }
        if ($path === '/dois/'.$clientDoi) {
            return Http::response(['data' => geofonRepairRemoteRecord(
                $clientDoi,
                'http://geofon.gfz.de/db/eqpage.php?id=gfz2009givj',
                client: 'other.repo',
            )]);
        }
        if ($path === '/dois/'.$remoteDoi) {
            return Http::response(['data' => geofonRepairRemoteRecord(
                '10.1594/gfz.geofon.gfz2011axdw',
                'http://geofon.gfz.de/db/eqpage.php?id=gfz2010dzva',
            )]);
        }

        return Http::response([], 500);
    });

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray(['updated' => 0, 'manual_review' => 2, 'errors' => 1])
        ->and($records[$missingDoi]['overall_status'])->toBe('remote_missing')
        ->and($records[$clientDoi]['overall_status'])->toBe('manual_review_datacite_client')
        ->and($records[$remoteDoi]['overall_status'])->toBe('manual_review_remote_doi');
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['HEAD', 'PUT'], true));
});

it('requires a known DataCite repository client and DOI state', function (): void {
    $missingClientDoi = '10.1594/gfz.geofon.gfz2008jhne';
    $unknownStateDoi = '10.1594/gfz.geofon.gfz2009groy';
    geofonRepairResource($missingClientDoi, 'db/eqpage.php?id=gfz2008jhne');
    geofonRepairResource($unknownStateDoi, 'db/eqpage.php?id=gfz2009groy');

    Http::fake(function (Request $request) use ($missingClientDoi, $unknownStateDoi) {
        $doi = rawurldecode(substr(
            (string) parse_url($request->url(), PHP_URL_PATH),
            strlen('/dois/'),
        ));
        $record = geofonRepairRemoteRecord(
            $doi,
            'http://geofon.gfz.de/db/eqpage.php?id='.str($doi)->afterLast('.'),
            state: $doi === $unknownStateDoi ? 'unknown' : 'findable',
        );
        if ($doi === $missingClientDoi) {
            unset($record['relationships']['client']);
        }

        return Http::response(['data' => $record]);
    });

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray(['manual_review' => 2, 'updated' => 0, 'errors' => 0])
        ->and($records[$missingClientDoi]['overall_status'])->toBe('manual_review_datacite_client')
        ->and($records[$unknownStateDoi]['overall_status'])->toBe('manual_review_datacite_state');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['HEAD', 'PUT'], true));
});

it('isolates transient and connection failures during the DataCite preflight', function (): void {
    $connectionDoi = '10.1594/gfz.geofon.gfz2008ewsv';
    $timeoutDoi = '10.1594/gfz.geofon.gfz2009gibb';
    $serverDoi = '10.1594/gfz.geofon.gfz2009givj';
    $limitedDoi = '10.1594/gfz.geofon.gfz2009kciu';
    foreach ([$connectionDoi, $timeoutDoi, $serverDoi, $limitedDoi] as $doi) {
        geofonRepairResource($doi, 'db/eqpage.php?id='.str($doi)->afterLast('.'));
    }

    Http::fake(function (Request $request) use ($connectionDoi, $timeoutDoi, $serverDoi) {
        $doi = rawurldecode(substr(
            (string) parse_url($request->url(), PHP_URL_PATH),
            strlen('/dois/'),
        ));

        return match ($doi) {
            $connectionDoi => throw new ConnectionException('Connection timed out'),
            $timeoutDoi => Http::response(['errors' => [['detail' => 'Request timeout']]], 408),
            $serverDoi => Http::response(['errors' => [['detail' => 'Server unavailable']]], 503),
            default => Http::response(
                ['errors' => [['detail' => 'Too many requests']]],
                429,
                ['Retry-After' => '1'],
            ),
        };
    });

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);

    expect($result)->toMatchArray(['candidates' => 4, 'updated' => 0, 'errors' => 4])
        ->and(collect($result['records'])->pluck('overall_status')->all())
        ->toBe([
            'datacite_preflight_failed',
            'datacite_preflight_failed',
            'datacite_preflight_failed',
            'datacite_preflight_failed',
        ]);
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['HEAD', 'PUT'], true));
});

it('keeps the local URL unchanged when DataCite rejects the update after snapshotting', function (): void {
    $doi = '10.1594/gfz.geofon.gfz2009kciu';
    $resource = geofonRepairResource($doi, 'db/eqpage.php?id=gfz2009kciu');
    Http::fake(function (Request $request) use ($doi) {
        if (parse_url($request->url(), PHP_URL_HOST) === 'geofon.gfz.de') {
            return Http::response('', 200);
        }
        if ($request->method() === 'PUT') {
            return Http::response(['errors' => [['detail' => 'Metadata rejected']]], 422);
        }

        return Http::response(['data' => geofonRepairRemoteRecord(
            $doi,
            'http://geofon.gfz.de/db/eqpage.php?id=gfz2009kciu',
        )]);
    });

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $record = $result['records'][0];

    expect($result)->toMatchArray(['updated' => 0, 'local_updated' => 0, 'errors' => 1])
        ->and($record['overall_status'])->toBe('datacite_update_failed')
        ->and($record['message'])->toBe('Metadata rejected')
        ->and($record['snapshot_path'])->toBeString()
        ->and($resource->landingPage->fresh()->external_path)->toBe('db/eqpage.php?id=gfz2009kciu');
    Storage::disk('local')->assertExists($record['snapshot_path']);
});

it('does not update ERNIE until a post-update DataCite GET confirms the target', function (): void {
    $doi = '10.1594/gfz.geofon.gfz2011ewla';
    $resource = geofonRepairResource($doi, 'db/eqpage.php?id=gfz2011ewla');
    $getCount = 0;
    Http::fake(function (Request $request) use ($doi, &$getCount) {
        if (parse_url($request->url(), PHP_URL_HOST) === 'geofon.gfz.de') {
            return Http::response('', 200);
        }
        if ($request->method() === 'PUT') {
            return Http::response(['data' => geofonRepairRemoteRecord(
                $doi,
                'https://geofon.gfz.de/eqinfo/event.php?id=gfz2011ewla',
            )]);
        }

        $getCount++;

        return Http::response(['data' => geofonRepairRemoteRecord(
            $doi,
            'http://geofon.gfz.de/db/eqpage.php?id=gfz2011ewla',
        )]);
    });

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);

    expect($getCount)->toBe(2)
        ->and($result)->toMatchArray(['updated' => 0, 'local_updated' => 0, 'errors' => 1])
        ->and($result['records'][0]['overall_status'])->toBe('datacite_verification_failed')
        ->and($resource->landingPage->fresh()->external_path)->toBe('db/eqpage.php?id=gfz2011ewla');
});

it('stops subsequent processing after a DataCite authentication failure', function (): void {
    $first = geofonRepairResource('10.1594/gfz.geofon.gfz2008jhne', 'db/eqpage.php?id=gfz2008jhne');
    $second = geofonRepairResource('10.1594/gfz.geofon.gfz2009gibb', 'db/eqpage.php?id=gfz2009gibb');
    Http::fake(['api.test.datacite.org/*' => Http::response([
        'errors' => [['detail' => 'Forbidden']],
    ], 403)]);

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray(['candidates' => 2, 'updated' => 0, 'skipped' => 1, 'errors' => 1])
        ->and($records[$first->doi]['overall_status'])->toBe('authentication_failed')
        ->and($records[$second->doi]['overall_status'])->toBe('not_processed_authentication');
    Http::assertSentCount(1);
});

it('detects a concurrent local edit after a successful remote update', function (): void {
    $doi = '10.1594/gfz.geofon.gfz2009gibb';
    $resource = geofonRepairResource($doi, 'db/eqpage.php?id=gfz2009gibb');
    $remoteUrl = 'http://geofon.gfz.de/db/eqpage.php?id=gfz2009gibb';
    Http::fake(function (Request $request) use ($doi, $resource, &$remoteUrl) {
        if (parse_url($request->url(), PHP_URL_HOST) === 'geofon.gfz.de') {
            LandingPage::query()->where('resource_id', $resource->id)->update([
                'external_path' => 'eqinfo/event.php?id=concurrent-edit',
            ]);

            return Http::response('', 200);
        }
        if ($request->method() === 'PUT') {
            $remoteUrl = (string) $request->data()['data']['attributes']['url'];
        }

        return Http::response(['data' => geofonRepairRemoteRecord($doi, $remoteUrl)]);
    });

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'updated' => 0,
        'datacite_updated' => 1,
        'concurrent_changes' => 1,
        'errors' => 1,
    ])->and($result['records'][0]['overall_status'])->toBe('concurrent_change')
        ->and($resource->landingPage->fresh()->external_path)->toBe('eqinfo/event.php?id=concurrent-edit')
        ->and($remoteUrl)->toBe('https://geofon.gfz.de/eqinfo/event.php?id=gfz2009gibb');
});

it('supports DOI, after ID, limit, and requested DOI diagnostics', function (): void {
    $before = geofonRepairResource('10.1594/gfz.geofon.gfz2008ewsv', 'db/eqpage.php?id=gfz2008ewsv');
    $first = geofonRepairResource('10.1594/gfz.geofon.gfz2009gibb', 'db/eqpage.php?id=gfz2009gibb');
    $second = geofonRepairResource('10.1594/gfz.geofon.gfz2009givj', 'db/eqpage.php?id=gfz2009givj');
    $remoteUrls = [
        $first->doi => 'http://geofon.gfz.de/db/eqpage.php?id=gfz2009gibb',
        $second->doi => 'http://geofon.gfz.de/db/eqpage.php?id=gfz2009givj',
    ];
    fakeGeofonRepairApi($remoteUrls);

    $result = app(GeofonEventLandingPageUrlRepairService::class)->run(
        afterId: $before->id,
        limit: 1,
        dois: [$first->doi, $second->doi, '10.1594/gfz.geofon.gfz2011axdw'],
    );
    $records = collect($result['records'])->keyBy('doi');

    expect($result)->toMatchArray([
        'resources_scanned' => 2,
        'candidates' => 2,
        'would_update' => 1,
        'skipped' => 1,
        'errors' => 1,
        'last_resource_id' => $first->id,
    ])->and($records[$second->doi]['overall_status'])->toBe('skipped_limit')
        ->and($records['10.1594/gfz.geofon.gfz2011axdw']['overall_status'])->toBe('requested_doi_not_found');
});

it('runs through Artisan, writes a spreadsheet-safe report, and returns failure for manual review', function (): void {
    $reportPath = storage_path('app/geofon-event-url-command-test.csv');
    File::delete($reportPath);
    $datacenters = [
        '10.1594/gfz.geofon.gfz2009groy' => '=FORMULA',
        '10.1594/gfz.geofon.gfz2009kciu' => "\tTabbed value",
        '10.1594/gfz.geofon.gfz2010dzva' => "\nNewline value",
        '10.1594/gfz.geofon.gfz2011ewla' => '  +FORMULA',
        '10.1594/gfz.geofon.gfz2008ewsv' => "\u{00A0}@FORMULA",
        '10.1594/gfz.geofon.gfz2009gibb' => '  Harmless value',
    ];
    foreach ($datacenters as $doi => $datacenter) {
        geofonRepairResource(
            $doi,
            'db/eqpage.php?id='.str($doi)->afterLast('.'),
            datacenter: $datacenter,
        );
    }
    Http::fake();

    $this->artisan('resources:repair-geofon-event-landing-page-urls', ['--report' => $reportPath])
        ->expectsOutput('Dry run only; no ERNIE or DataCite records were changed.')
        ->expectsOutput('Some records need manual review and were not changed; inspect the CSV report.')
        ->assertExitCode(Command::FAILURE);

    $stream = fopen($reportPath, 'rb');
    expect($stream)->not->toBeFalse();
    $header = fgetcsv($stream, escape: '');
    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    expect($header)->toBeArray();
    $datacenterColumn = array_search('datacenter', $header, true);
    $statusColumn = array_search('overall_status', $header, true);
    expect($datacenterColumn)->toBeInt()
        ->and($statusColumn)->toBeInt()
        ->and(array_column($rows, $datacenterColumn))->toBe([
            "'=FORMULA",
            "'\tTabbed value",
            "'\nNewline value",
            "'  +FORMULA",
            "'\u{00A0}@FORMULA",
            '  Harmless value',
        ])->and(array_unique(array_column($rows, $statusColumn)))
        ->toBe(['manual_review_wrong_datacenter']);
    File::delete($reportPath);
});

it('neutralizes whitespace-prefixed DataCite errors in the CSV report', function (): void {
    $reportPath = storage_path('app/geofon-event-url-datacite-error-test.csv');
    File::delete($reportPath);
    geofonRepairResource(
        '10.1594/gfz.geofon.gfz2009givj',
        'db/eqpage.php?id=gfz2009givj',
    );
    Http::fake(fn (): never => throw new ConnectionException(" \t=REMOTE_ERROR"));

    $this->artisan('resources:repair-geofon-event-landing-page-urls', ['--report' => $reportPath])
        ->assertExitCode(Command::FAILURE);

    $stream = fopen($reportPath, 'rb');
    expect($stream)->not->toBeFalse();
    $header = fgetcsv($stream, escape: '');
    $row = fgetcsv($stream, escape: '');
    fclose($stream);

    expect($header)->toBeArray()
        ->and($row)->toBeArray();
    $messageColumn = array_search('message', $header, true);
    expect($messageColumn)->toBeInt()
        ->and($row[$messageColumn])->toBe("' \t=REMOTE_ERROR");
    File::delete($reportPath);
});

it('fails the command when the CSV header or a data row cannot be written', function (): void {
    $scheme = 'geofon-failing-csv';
    $wrapperDirectory = (string) getcwd().DIRECTORY_SEPARATOR.$scheme.':';
    File::makeDirectory($wrapperDirectory);
    expect(stream_wrapper_register($scheme, GeofonFailingCsvStreamWrapper::class))->toBeTrue();

    geofonRepairResource(
        '10.1594/gfz.geofon.gfz2009groy',
        'db/eqpage.php?id=gfz2009groy',
        datacenter: 'Unexpected Datacenter',
    );

    try {
        GeofonFailingCsvStreamWrapper::$successfulWrites = 0;
        $this->artisan('resources:repair-geofon-event-landing-page-urls', [
            '--report' => $scheme.'://header.csv',
        ])->expectsOutputToContain('Unable to write GEOFON event URL report: Unable to write report header:')
            ->doesntExpectOutputToContain('GEOFON event URL report written to')
            ->assertExitCode(Command::FAILURE);

        GeofonFailingCsvStreamWrapper::$successfulWrites = 1;
        $this->artisan('resources:repair-geofon-event-landing-page-urls', [
            '--report' => $scheme.'://row.csv',
        ])->expectsOutputToContain('Unable to write GEOFON event URL report: Unable to write report row 1:')
            ->doesntExpectOutputToContain('GEOFON event URL report written to')
            ->assertExitCode(Command::FAILURE);
    } finally {
        stream_wrapper_unregister($scheme);
        File::deleteDirectory($wrapperDirectory);
    }
});

it('rejects production writes without explicit confirmation and invalid options', function (): void {
    config(['datacite.test_mode' => false]);
    Http::fake();

    $this->artisan('resources:repair-geofon-event-landing-page-urls --apply')
        ->expectsOutput('Production writes require both --apply and --force-production.')
        ->assertExitCode(Command::INVALID);

    config(['datacite.test_mode' => true]);
    $this->artisan('resources:repair-geofon-event-landing-page-urls', ['--limit' => '-1'])
        ->expectsOutput('The --limit option must be a non-negative integer.')
        ->assertExitCode(Command::INVALID);
    $this->artisan('resources:repair-geofon-event-landing-page-urls', ['--doi' => ['not-a-doi']])
        ->expectsOutput('Invalid DOI filter: not-a-doi')
        ->assertExitCode(Command::INVALID);
    Http::assertNothingSent();
});
