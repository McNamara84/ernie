<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createLegacyIgsnAuditRelation(string $identifier, bool $published = true, string $relationType = 'IsIdenticalTo'): RelatedIdentifier
{
    $identifierType = IdentifierType::query()->firstOrCreate(
        ['slug' => 'IGSN'],
        ['name' => 'IGSN', 'is_active' => true, 'is_elmo_active' => true],
    );
    $relation = RelationType::query()->firstOrCreate(
        ['slug' => $relationType],
        ['name' => $relationType, 'is_active' => true, 'is_elmo_active' => true],
    );
    $resource = Resource::factory()->create(['doi' => '10.60510/'.strtolower(str_replace('/', '-', $identifier))]);
    LandingPage::factory()->for($resource)->create([
        'doi_prefix' => $resource->doi,
        'is_published' => $published,
    ]);

    return RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => $identifier,
        'identifier_type_id' => $identifierType->id,
        'relation_type_id' => $relation->id,
        'position' => 1,
    ]);
}

test('legacy IGSN audit succeeds when every published identity Handle resolves', function () {
    createLegacyIgsnAuditRelation('10273/GFBNO7002EXZ3001');
    createLegacyIgsnAuditRelation('10273/GFLMU0002');

    Http::fake([
        'hdl.handle.net/api/handles/*' => Http::response(['responseCode' => 1, 'values' => []]),
    ]);

    $this->artisan('igsn:audit-legacy-handles --batch=2')
        ->expectsOutputToContain('Auditing 2 published legacy IGSN Handle(s)')
        ->expectsOutputToContain('Resolved: 2')
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hdl.handle.net/api/handles/10273/GFBNO7002EXZ3001');
});

test('legacy IGSN audit fails and writes a machine-readable report for unresolved Handles', function () {
    $resolved = createLegacyIgsnAuditRelation('10273/GFBRESOLVED');
    $missing = createLegacyIgsnAuditRelation('10273/GFBMISSING');
    $reportPath = sys_get_temp_dir().'/ernie-igsn-audit-'.bin2hex(random_bytes(5)).'.json';

    Http::fake(fn (Request $request) => str_ends_with($request->url(), 'GFBRESOLVED')
        ? Http::response(['responseCode' => 1])
        : Http::response(['responseCode' => 100]));

    try {
        $this->artisan('igsn:audit-legacy-handles', ['--output' => $reportPath])
            ->expectsOutputToContain('Resolved: 1')
            ->expectsOutput('Missing: 1')
            ->expectsOutput('Transient or unknown: 0')
            ->assertFailed();

        $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);

        expect($report)
            ->toMatchArray(['checked' => 2, 'resolved' => 1, 'failed' => 1])
            ->and($report['failures'][0])
            ->toMatchArray([
                'related_identifier_id' => $missing->id,
                'resource_id' => $missing->resource_id,
                'identifier' => '10273/GFBMISSING',
                'classification' => 'missing',
                'status' => 'handle-response-100',
            ]);
    } finally {
        if (is_file($reportPath)) {
            unlink($reportPath);
        }
    }

    expect($resolved->fresh()->identifier)->toBe('10273/GFBRESOLVED');
});

test('legacy IGSN audit retries and classifies transient API failures separately', function () {
    createLegacyIgsnAuditRelation('10273/GFBTEMPORARY');

    Http::fake([
        'hdl.handle.net/api/handles/*' => Http::response(['message' => 'temporarily unavailable'], 503),
    ]);

    $this->artisan('igsn:audit-legacy-handles')
        ->expectsOutput('Missing: 0')
        ->expectsOutput('Transient or unknown: 1')
        ->assertFailed();

    Http::assertSentCount(3);
});

test('legacy IGSN audit ignores drafts, non-identity relations and modern IGSNs', function () {
    createLegacyIgsnAuditRelation('10273/GFBDRAFT', false);
    createLegacyIgsnAuditRelation('10273/GFBRELATED', true, 'References');
    createLegacyIgsnAuditRelation('GFBMODERN');

    Http::fake();

    $this->artisan('igsn:audit-legacy-handles')
        ->expectsOutput('No published legacy IGSN Handles found.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('legacy IGSN audit fails when an empty report cannot be written', function () {
    Http::fake();

    $this->artisan('igsn:audit-legacy-handles', ['--output' => sys_get_temp_dir()])
        ->expectsOutput('No published legacy IGSN Handles found.')
        ->expectsOutputToContain('Could not write audit report')
        ->assertFailed();

    Http::assertNothingSent();
});
