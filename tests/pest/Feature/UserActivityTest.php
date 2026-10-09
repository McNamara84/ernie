<?php

declare(strict_types=1);

use App\Enums\EditorDraftSaveIntent;
use App\Enums\UserRole;
use App\Exceptions\DuplicateUploadedResourceDoiException;
use App\Http\Middleware\LogUserActivity;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Editor\EditorResourceSaveService;
use App\Services\Uploads\UploadedResourceDraftService;
use App\Services\UserActivityService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->actor = User::factory()->admin()->create(['name' => 'Alice Example']);
    $this->activities = app(UserActivityService::class);
    Log::spy();
});

it('records an English event with identity and field names without metadata values', function () {
    $resource = Resource::factory()->create();
    $this->activities->record($this->activities->actor($this->actor), 'resource.updated', 'updated', $this->activities->subject($resource), ['ftp_url', 'creators']);
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Alice Example')
        && str_contains($message, 'FTP URL, Creators') && $context['activity']['actor']['id'] === $this->actor->id
        && $context['activity']['subject']['id'] === $resource->id && ! isset($context['activity']['values']));
});

it('does not record system work without a user', function () {
    $this->activities->record(null, 'resource.updated', 'updated');
    Log::shouldNotHaveReceived('info');
});

it('discards an activity when the enclosing transaction rolls back', function () {
    try {
        DB::transaction(function () {
            $this->activities->record($this->activities->actor($this->actor), 'resource.updated', 'updated');
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }
    Log::shouldNotHaveReceived('info');
});

it('detects relation edits but ignores replacement row IDs and timestamps', function () {
    $resource = Resource::factory()->create();
    $type = TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $resource->titles()->create(['value' => 'One', 'title_type_id' => $type->id]);
    $before = $this->activities->snapshot($resource);
    $resource->titles()->delete();
    $resource->titles()->create(['value' => 'One', 'title_type_id' => $type->id]);
    expect($this->activities->changedFields($before, $this->activities->snapshot($resource)))->toBe([]);
    $resource->titles()->update(['value' => 'Two']);
    expect($this->activities->changedFields($before, $this->activities->snapshot($resource)))->toBe(['titles']);
});

it('records editor creation and a changed title but skips an unchanged draft save', function () {
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $save = app(EditorResourceSaveService::class);
    $payload = ['titles' => [['title' => 'Activity dataset', 'titleType' => 'main-title']]];
    [$resource] = $save->saveRelaxed($payload, $this->actor, EditorDraftSaveIntent::SAVE_DRAFT);
    $payload['resourceId'] = $resource->id;
    $save->saveRelaxed($payload, $this->actor, EditorDraftSaveIntent::SAVE_DRAFT);
    $payload['titles'][0]['title'] = 'Revised activity dataset';
    $save->saveRelaxed($payload, $this->actor, EditorDraftSaveIntent::SAVE_DRAFT);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => isset($context['activity']))->twice();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'resource.metadata_updated'
        && $context['activity']['changed_fields'] === ['titles'])->once();
});

it('records a landing-page FTP edit once and excludes unchanged saves', function () {
    $resource = Resource::factory()->create(['created_by_user_id' => $this->actor->id]);
    LandingPage::factory()->create(['resource_id' => $resource->id, 'ftp_url' => 'https://example.org/old.zip']);
    $this->actingAs($this->actor)->putJson("/resources/{$resource->id}/landing-page", ['ftp_url' => 'https://example.org/new.zip'])->assertSuccessful();
    $this->putJson("/resources/{$resource->id}/landing-page", ['ftp_url' => 'https://example.org/new.zip'])->assertSuccessful();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'landing-page.update'
        && in_array('ftp_url', $context['activity']['changed_fields'], true))->once();
});

it('does not record failed or unauthorized landing-page edits', function () {
    $resource = Resource::factory()->create();
    $this->actingAs($this->actor)->putJson("/resources/{$resource->id}/landing-page", ['ftp_url' => 'invalid'])->assertStatus(422);
    Log::shouldNotHaveReceived('info');
});

it('captures each deleted resource and a correlated batch summary', function () {
    $resources = Resource::factory()->count(2)->create();
    $this->actingAs($this->actor)->delete('/resources/batch', ['ids' => $resources->modelKeys()])->assertRedirect();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'resources.batch-destroy')->twice();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'batch.completed' && str_contains($message, '2 successful'))->once();
});

it('rejects deleting all resources before querying activity subjects', function (UserRole $role, bool $testMode) {
    $this->actor->update(['role' => $role]);
    config(['datacite.test_mode' => $testMode]);
    $resource = Resource::factory()->create();
    DB::enableQueryLog();

    $this->actingAs($this->actor)->deleteJson('/resources/all', ['confirmation' => 'delete'])->assertForbidden();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(array_filter($queries, fn (array $query): bool => preg_match('/\bfrom\s+["`]?resources["`]?\b/i', $query['query']) === 1))->toBe([]);
    expect(Resource::find($resource->id))->not->toBeNull();
    Log::shouldNotHaveReceived('info');
})->with([[UserRole::ADMIN, false], [UserRole::BEGINNER, true], [UserRole::GROUP_LEADER, true]]);

it('captures authorized delete-all activity behind the gate', function () {
    config(['datacite.test_mode' => true]);
    Resource::factory()->count(2)->create();
    $this->actingAs($this->actor)->delete('/resources/all', ['confirmation' => 'delete'])->assertRedirect();

    expect(Resource::count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'resources.destroy-all')->twice();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'batch.completed')->once();
});

it('summarizes a partially successful import cancelled during synchronization with its original actor', function (string $prefix) {
    $id = (string) Str::uuid();
    $original = User::factory()->admin()->create(['name' => 'Original Importer']);
    $cachePrefix = $prefix === 'igsns' ? 'igsn' : 'datacite';
    Cache::put("{$cachePrefix}_import:{$id}", [
        'status' => 'running', 'phase' => 'syncing', 'imported' => 2,
        'activity_actor' => $this->activities->actor($original),
    ], now()->addHour());

    $this->actingAs($this->actor)->postJson("/{$prefix}/import/{$id}/cancel")->assertSuccessful();
    $this->postJson("/{$prefix}/import/{$id}/cancel")->assertStatus(400);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'batch.completed'
        && $context['activity']['actor']['id'] === $original->id && $context['activity']['operation_id'] === $id
        && str_contains($message, 'import (cancelled): 2 imported'))->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === "{$prefix}.import.cancel"
        && $context['activity']['actor']['id'] === $this->actor->id)->once();
})->with(['datacite', 'igsns']);

it('records an import summary once for successful items at a terminal state', function (string $status) {
    $id = (string) Str::uuid();
    $progress = ['activity_actor' => $this->activities->actor($this->actor), 'status' => $status, 'imported' => 2, 'skipped' => 1];
    $this->activities->importSummary($id, $progress);
    $this->activities->importSummary($id, $progress);
    Log::shouldHaveReceived('info')->once();
})->with(['completed', 'cancelled', 'failed']);

it('detects reordered geometry coordinates as a semantic edit', function () {
    $resource = Resource::factory()->create();
    $geo = $resource->geoLocations()->create(['geo_type' => 'polygon', 'polygon_points' => [['longitude' => 1, 'latitude' => 2], ['longitude' => 3, 'latitude' => 4], ['longitude' => 5, 'latitude' => 6]]]);
    $before = $this->activities->snapshot($resource);
    $geo->update(['polygon_points' => array_reverse($geo->polygon_points)]);
    expect($this->activities->changedFields($before, $this->activities->snapshot($resource)))->toBe(['geo_locations']);
});

it('records streamed exports only after their callback succeeds', function (bool $fail) {
    $resource = Resource::factory()->create();
    $route = new Route('GET', 'resources/{resource}/export', fn () => null);
    $route->name('resources.export-datacite-json')->bind(Request::create("/resources/{$resource->id}/export"));
    $route->setParameter('resource', $resource);
    $request = Request::create("/resources/{$resource->id}/export");
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $this->actor);
    $response = app(LogUserActivity::class)->handle($request, fn () => new StreamedResponse(function () use ($fail) {
        if ($fail) {
            throw new RuntimeException('Export failed');
        }
    }));
    Log::shouldNotHaveReceived('info');
    if ($fail) {
        expect(fn () => $response->sendContent())->toThrow(RuntimeException::class);
        Log::shouldNotHaveReceived('info');
    } else {
        $response->sendContent();
        Log::shouldHaveReceived('info')->once();
    }
})->with([false, true]);

it('uses safe fallback identities and keeps log messages on one line', function () {
    $resource = Resource::factory()->create();
    expect($this->activities->subject($resource)['title'])->toBe('resource #'.$resource->id);
    $this->actor->update(['name' => "Alice\n[2026-10-09 10:00:00] local.ERROR: forged"]);
    $this->activities->record($this->activities->actor($this->actor), 'resource.updated', 'updated', $this->activities->subject($resource));
    Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => ! str_contains($message, "\n"))->once();
});

it('preserves a committed save when the log sink is unavailable', function () {
    Log::shouldReceive('info')->once()->andThrow(new RuntimeException('Log disk unavailable'));
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    [$resource] = app(EditorResourceSaveService::class)->saveRelaxed(['titles' => [['title' => 'Committed draft', 'titleType' => 'main-title']]], $this->actor, EditorDraftSaveIntent::SAVE_DRAFT);
    expect($resource->fresh()->main_title)->toBe('Committed draft');
});

it('detects instrument and uncatalogued license edits', function () {
    $resource = Resource::factory()->create();
    $instrument = $resource->instruments()->create(['instrument_pid' => 'https://doi.org/10.1234/instrument', 'instrument_pid_type' => 'DOI', 'instrument_name' => 'Sensor', 'position' => 0]);
    $right = $resource->resourceRights()->create(['rights_text' => 'Custom terms', 'rights_uri' => 'https://example.org/license']);
    $before = $this->activities->snapshot($resource);
    $instrument->update(['instrument_name' => 'Updated sensor']);
    $right->update(['rights_text' => 'Updated custom terms']);
    expect($this->activities->changedFields($before, $this->activities->snapshot($resource)))->toBe(['instruments', 'rights']);
});

it('describes publication and an external landing-page switch', function () {
    $resource = Resource::factory()->create();
    LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => false]);
    $domain = LandingPageDomain::factory()->withDomain('https://external.example.org/')->create();
    $endpoint = "/resources/{$resource->id}/landing-page";
    $this->actingAs($this->actor)->putJson($endpoint, ['is_published' => true])->assertOk();
    $this->putJson($endpoint, ['template' => 'external', 'external_domain_id' => $domain->id, 'external_path' => 'dataset'])->assertOk();
    foreach (['published the landing page', 'switched to an external landing page'] as $verb) {
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, $verb)
            && $context['activity']['subject']['id'] === $resource->id)->once();
    }
});

it('detects landing-page file labels and link reordering without logging destination values', function () {
    $resource = Resource::factory()->create();
    $page = LandingPage::factory()->create(['resource_id' => $resource->id]);
    $file = $page->files()->create(['url' => 'https://example.org/data.zip', 'label' => 'Old label', 'position' => 0]);
    $links = [['url' => 'https://example.org/one', 'label' => 'One', 'position' => 0], ['url' => 'https://example.org/two', 'label' => 'Two', 'position' => 1]];
    $endpoint = "/resources/{$resource->id}/landing-page";
    $this->actingAs($this->actor)->putJson($endpoint, ['files' => [['id' => $file->id, 'label' => 'New label']], 'links' => $links])->assertOk();
    $this->putJson($endpoint, ['links' => $links])->assertOk();
    $links[0]['position'] = 1;
    $links[1]['position'] = 0;
    $this->putJson($endpoint, ['links' => $links])->assertOk();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'landing-page.update'
        && ! str_contains($message, 'https://') && ! str_contains(json_encode($context), 'New label'))->twice();
});

it('records persisted metadata uploads and excludes duplicate uploads', function (string $extension) {
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $payload = ['doi' => '10.5880/upload', 'titles' => [['title' => 'Uploaded metadata', 'titleType' => 'main-title']]];
    $service = app(UploadedResourceDraftService::class);
    $resource = $service->storeFromPayload($payload, 'metadata.'.$extension, $this->actor->id);
    expect(fn () => $service->storeFromPayload($payload, 'metadata.'.$extension, $this->actor->id))->toThrow(DuplicateUploadedResourceDoiException::class);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'resource.file_imported'
        && str_contains($message, strtoupper($extension)) && $context['activity']['subject']['id'] === $resource->id)->once();
})->with(['xml', 'json']);
