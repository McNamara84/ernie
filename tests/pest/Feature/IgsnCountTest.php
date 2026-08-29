<?php

declare(strict_types=1);

use App\Http\Controllers\IgsnController;
use App\Models\DateType;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
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

it('keeps title selection and collected date ranges unchanged', function (): void {
    $mainTitleType = TitleType::factory()->create(['name' => 'Main Title', 'slug' => 'MainTitle']);
    $alternativeTitleType = TitleType::factory()->create(['name' => 'Alternative Title', 'slug' => 'AlternativeTitle']);
    $collectedDateType = DateType::factory()->create(['name' => 'Collected', 'slug' => 'Collected']);
    $resource = createCountTestIgsn($this->physicalObjectType, '10.60516/FORMATTED');
    $resource->titles()->create(['value' => 'Alternative', 'title_type_id' => $alternativeTitleType->id]);
    $resource->titles()->create(['value' => 'Main sample title', 'title_type_id' => $mainTitleType->id]);
    $resource->dates()->create([
        'date_type_id' => $collectedDateType->id,
        'start_date' => '2024-01-10',
        'end_date' => '2024-01-20',
    ]);

    $this->actingAs($this->user)
        ->get('/igsns')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('igsns.0.title', 'Main sample title')
            ->where('igsns.0.collection_date', '2024-01-10 – 2024-01-20')
        );
});

it('falls back safely when title and collected-date vocabularies are missing', function (): void {
    $alternativeTitleType = TitleType::factory()->create(['name' => 'Alternative Title', 'slug' => 'AlternativeTitle']);
    $withFallbackTitle = createCountTestIgsn($this->physicalObjectType, '10.60516/FALLBACK');
    $withFallbackTitle->titles()->create(['value' => 'Fallback title', 'title_type_id' => $alternativeTitleType->id]);
    createCountTestIgsn($this->physicalObjectType, '10.60516/UNTITLED');

    $this->actingAs($this->user)
        ->get('/igsns?sort=igsn&direction=asc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('igsns.0.title', 'Fallback title')
            ->where('igsns.0.collection_date', null)
            ->where('igsns.1.title', 'Untitled')
            ->where('igsns.1.collection_date', null)
        );
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
