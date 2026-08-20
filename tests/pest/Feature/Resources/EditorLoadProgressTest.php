<?php

declare(strict_types=1);

use App\Enums\EditorLoadStage;
use App\Http\Controllers\EditorController;
use App\Models\DateType;
use App\Models\Description;
use App\Models\Resource;
use App\Models\User;
use App\Services\Editor\EditorDataTransformer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

use function Pest\Laravel\withoutVite;

beforeEach(function (): void {
    withoutVite();
    config()->set('cache.default', 'array');
    Cache::flush();
});

it('renders a lightweight loader before loading an existing resource', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertOk();

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('editor-loading')
        ->where('editorLoad.resourceId', $resource->id)
        ->where('editorLoad.serverProgress', 0)
        ->where('editorLoad.slowThresholdMs', 12_000)
        ->where('loadError', null));

    expect($response->inertiaProps('editorLoad.token'))->toBeString()->not->toBeEmpty();
});

it('rejects resource IDs that are not positive integers without normalizing them', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create();

    $this->actingAs($user);

    foreach ([
        "{$resource->id}.9",
        "{$resource->id}e0",
        '0',
        '-1',
        'not-an-id',
    ] as $malformedResourceId) {
        $this->get(route('editor').'?resourceId='.rawurlencode($malformedResourceId))
            ->assertBadRequest();
    }
});

it('loads the resource on the authenticated token request and reaches server ready', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create(['doi' => '10.5880/editor.progress']);

    $this->actingAs($user);
    $loader = $this->get(route('editor', ['resourceId' => $resource->id]))->assertOk();
    $token = $loader->inertiaProps('editorLoad.token');

    expect($token)->toBeString();

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('editor')
            ->where('resourceId', (string) $resource->id)
            ->where('doi', '10.5880/editor.progress')
            ->where('editorLoad.token', $token)
            ->where('editorLoad.serverProgress', 75));

    $this->get(route('editor.resource-loads.status', ['token' => $token]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'status' => 'server_ready',
            'stage' => EditorLoadStage::SERVER_READY->value,
            'progress' => 75,
            'error' => null,
        ]);
});

it('renders the loader error page when progress expires while handling a load failure', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create();

    $this->mock(EditorDataTransformer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getCommonProps')
            ->once()
            ->andReturnUsing(function (): array {
                Cache::flush();

                throw new RuntimeException('Resource transformation failed.');
            });
    });

    $this->actingAs($user);
    $loader = $this->get(route('editor', ['resourceId' => $resource->id]))->assertOk();
    $token = $loader->inertiaProps('editorLoad.token');

    expect($token)->toBeString();

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('editor-loading')
            ->where('editorLoad.resourceId', $resource->id)
            ->where('editorLoad.serverProgress', 0)
            ->where('loadError', 'Unable to load this resource in the Data Editor. Please try again.'));
});

it('renders the loader error page when progress tracking also fails', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create();
    $cacheStore = new class extends ArrayStore
    {
        public ?int $editorLoadGetsBeforeFailure = null;

        public function get($key): mixed
        {
            if ($this->editorLoadGetsBeforeFailure !== null && str_starts_with((string) $key, 'editor_load:')) {
                if ($this->editorLoadGetsBeforeFailure === 0) {
                    throw new RuntimeException('Cache unavailable.');
                }

                $this->editorLoadGetsBeforeFailure--;
            }

            return parent::get($key);
        }
    };

    Cache::extend('failing-test', fn (): Repository => new Repository($cacheStore));
    config()->set('cache.stores.failing-test', ['driver' => 'failing-test']);
    config()->set('cache.default', 'failing-test');

    $this->mock(EditorDataTransformer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getCommonProps')
            ->once()
            ->andThrow(new RuntimeException('Resource transformation failed.'));
    });

    $this->actingAs($user);
    $loader = $this->get(route('editor', ['resourceId' => $resource->id]))->assertOk();
    $token = $loader->inertiaProps('editorLoad.token');

    expect($token)->toBeString();

    $cacheStore->editorLoadGetsBeforeFailure = 1;

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('editor-loading')
            ->where('editorLoad.resourceId', $resource->id)
            ->where('editorLoad.serverProgress', 0)
            ->where('loadError', 'Unable to load this resource in the Data Editor. Please try again.'));
});

it('eager loads nested description and date types without per-row queries', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create();
    Description::factory()->count(3)->create(['resource_id' => $resource->id]);
    $dateType = DateType::query()->create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    foreach (range(1, 3) as $day) {
        $resource->dates()->create([
            'date_type_id' => $dateType->id,
            'start_date' => sprintf('2026-08-%02d', $day),
        ]);
    }

    $this->actingAs($user);
    $loader = $this->get(route('editor', ['resourceId' => $resource->id]))->assertOk();
    $token = $loader->inertiaProps('editorLoad.token');
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('editor')
            ->has('descriptions', 3)
            ->has('dates', 3));

    $descriptionTypeQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'description_types'));
    $dateTypeQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'date_types'));

    expect($descriptionTypeQueries)->toHaveCount(1)
        ->and($dateTypeQueries)->toHaveCount(1);
});

it('does not allow a load token to cross users or resources', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $resource = Resource::factory()->create();
    $otherResource = Resource::factory()->create();

    $this->actingAs($owner);
    $loader = $this->get(route('editor', ['resourceId' => $resource->id]))->assertOk();
    $token = $loader->inertiaProps('editorLoad.token');

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get(route('editor', ['resourceId' => $otherResource->id]))
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->get(route('editor.resource-loads.status', ['token' => $token]))
        ->assertNotFound();

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertNotFound();
});

it('rejects expired and malformed progress tokens', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create();

    $this->actingAs($user);
    $loader = $this->get(route('editor', ['resourceId' => $resource->id]))->assertOk();
    $token = $loader->inertiaProps('editorLoad.token');

    Cache::flush();

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertNotFound();

    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, 'not-a-uuid')
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertNotFound();
});

it('validates client slow-load reports and hides tokens from other users', function (): void {
    $user = User::factory()->create();
    $resource = Resource::factory()->create();

    $this->actingAs($user);
    $loader = $this->get(route('editor', ['resourceId' => $resource->id]))->assertOk();
    $token = $loader->inertiaProps('editorLoad.token');

    $this->postJson(route('editor.resource-loads.slow', ['token' => $token]), [
        'stage' => 'made_up_stage',
        'progress' => 101,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['stage', 'progress']);

    $this->postJson(route('editor.resource-loads.slow', ['token' => $token]), [
        'stage' => 'client_vocabularies',
        'progress' => 90,
    ])->assertNoContent();

    $this->postJson(route('editor.resource-loads.slow', ['token' => $token]), [
        'stage' => 'loader',
        'progress' => 0,
    ])->assertNoContent();

    $this->postJson(route('editor.resource-loads.slow', ['token' => $token]), [
        'stage' => 'client_ready',
        'progress' => 100,
    ])->assertNoContent();

    $this->postJson(route('editor.resource-loads.slow', ['token' => $token]))
        ->assertNoContent();

    $this->postJson(route('editor.resource-loads.slow', ['token' => $token]), [
        'progress' => -1,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['progress']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.resource-loads.slow', ['token' => $token]), [
            'stage' => 'client_ready',
            'progress' => 100,
        ])->assertNotFound();
});

it('requires authentication for client slow-load reports', function (): void {
    $this->postJson(route('editor.resource-loads.slow', ['token' => fake()->uuid()]), [
        'stage' => 'client_ready',
        'progress' => 100,
    ])->assertUnauthorized();
});

it('leaves non-resource editor modes on their existing direct path', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('editor'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('editor')
            ->missing('editorLoad'));
});
