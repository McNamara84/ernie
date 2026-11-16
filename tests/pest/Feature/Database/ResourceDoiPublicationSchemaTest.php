<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceDoiPublication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses()->group('database', 'mysql-sensitive');

function publicationSchemaValues(Resource $resource): array
{
    return [
        'uuid' => (string) Str::uuid(), 'resource_id' => $resource->id, 'test_mode' => true,
        'endpoint' => 'https://api.test.datacite.org', 'repository' => 'test.repo', 'prefix' => '10.83279',
        'target_url' => 'https://ernie.example/datasets/'.$resource->id,
        'metadata_hash' => str_repeat('a', 64), 'payload' => ['data' => ['attributes' => ['titles' => [['title' => 'Approved']]]]],
    ];
}

it('migrates existing drafts without assuming registration or publication', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.83279/preassigned']);
    $page = LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    $migration = require database_path('migrations/2026_11_11_000001_create_resource_doi_publications_table.php');
    Http::fake();
    $migration->down();
    try {
        expect(Schema::hasTable('resource_doi_publications'))->toBeFalse();
    } finally {
        $migration->up();
    }
    expect($page->fresh()->is_published)->toBeFalse()->and($resource->fresh()->doi)->toBe('10.83279/preassigned')
        ->and(ResourceDoiPublication::count())->toBe(0)
        ->and(Schema::hasColumn('igsn_registration_runs', 'datacite_context_fingerprint'))->toBeTrue();
    Http::assertNothingSent();
});

it('allows exactly one active publication per resource even across environments', function (): void {
    $resource = Resource::factory()->create();
    $first = ResourceDoiPublication::create(publicationSchemaValues($resource));
    expect(fn () => ResourceDoiPublication::create([...publicationSchemaValues($resource), 'test_mode' => false]))->toThrow(QueryException::class);
    $first->update(['status' => 'succeeded']);
    $next = ResourceDoiPublication::create(publicationSchemaValues($resource));
    expect($first->fresh()->active_resource_id)->toBeNull()->and($next->active_resource_id)->toBe($resource->id);
});

it('retains the approved payload after actor deletion and cascades resource deletion', function (): void {
    $resource = Resource::factory()->create();
    $user = User::factory()->create();
    $publication = ResourceDoiPublication::create([...publicationSchemaValues($resource), 'user_id' => $user->id]);
    $user->delete();
    expect($publication->fresh()->user_id)->toBeNull()->and($publication->fresh()->payload['data']['attributes']['titles'][0]['title'])->toBe('Approved');
    $resource->delete();
    expect($publication->fresh())->toBeNull();
});
