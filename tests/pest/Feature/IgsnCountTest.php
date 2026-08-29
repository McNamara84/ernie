<?php

declare(strict_types=1);

use App\Http\Controllers\IgsnController;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);
covers(IgsnController::class);

beforeEach(function (): void {
    withoutVite();
    $this->physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);
    $this->user = User::factory()->create();
});

it('renders IGSN results before exact counts are available', function (): void {
    createCountTestIgsn($this->physicalObjectType, '10.60516/ONE');
    createCountTestIgsn($this->physicalObjectType, '10.60516/TWO');

    $this->actingAs($this->user)
        ->get('/igsns?per_page=10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('igsns', 2)
            ->where('pagination.total', null)
            ->where('pagination.last_page', null)
            ->where('pagination.count_status', 'pending')
            ->where('pagination.has_more', false)
            ->where('totalCount', null)
        );
});

it('returns filtered and inventory totals with matching IGSN semantics', function (): void {
    createCountTestIgsn($this->physicalObjectType, '10.60516/PENDING', 'pending');
    createCountTestIgsn($this->physicalObjectType, '10.60516/UPLOADED', 'uploaded');
    createCountTestIgsn($this->physicalObjectType, '10.99999/OTHER', 'pending');

    $this->actingAs($this->user)
        ->getJson('/igsns/count?prefix=10.60516&status=pending&per_page=10')
        ->assertOk()
        ->assertJsonPath('filtered_total', 1)
        ->assertJsonPath('inventory_total', 3)
        ->assertJsonPath('last_page', 1)
        ->assertJsonPath('count_status', 'ready')
        ->assertJsonStructure(['filter_fingerprint']);
});

it('keeps count fingerprints independent of pagination and sorting', function (): void {
    createCountTestIgsn($this->physicalObjectType, '10.60516/ONE');

    $first = $this->actingAs($this->user)
        ->getJson('/igsns/count?prefix=10.60516&per_page=10&sort=title&direction=asc&page=2')
        ->assertOk()
        ->json('filter_fingerprint');
    $second = $this->actingAs($this->user)
        ->getJson('/igsns/count?direction=desc&sort=updated_at&per_page=100&prefix=10.60516')
        ->assertOk()
        ->json('filter_fingerprint');

    expect($first)->toBeString()->toBe($second);
});

it('serves repeated IGSN counts from cache without another count query', function (): void {
    createCountTestIgsn($this->physicalObjectType, '10.60516/ONE');

    $this->actingAs($this->user)->getJson('/igsns/count?prefix=10.60516')->assertOk();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $this->actingAs($this->user)->getJson('/igsns/count?prefix=10.60516')->assertOk();

    expect(array_filter(
        $queries,
        static fn (string $sql): bool => preg_match('/\bcount\s*\(/', $sql) === 1,
    ))->toBeEmpty();
});

it('requires authentication for the IGSN count endpoint', function (): void {
    $this->getJson('/igsns/count')->assertUnauthorized();
});

function createCountTestIgsn(ResourceType $type, string $doi, string $status = 'pending'): Resource
{
    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
        'doi' => $doi,
    ]);

    IgsnMetadata::query()->create([
        'resource_id' => $resource->id,
        'upload_status' => $status,
    ]);

    return $resource;
}
