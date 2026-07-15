<?php

declare(strict_types=1);

use App\Http\Controllers\AssistanceController;
use App\Models\DismissedRelation;
use App\Models\IdentifierType;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceContributor;
use App\Models\SuggestedRelation;
use App\Models\SuggestedRor;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

covers(AssistanceController::class);

beforeEach(function (): void {
    Config::set('cache.default', 'array');
    Queue::fake();

    $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabelService
        ->shouldReceive('resolveBestEffort')
        ->andReturn(null)
        ->byDefault();
    app()->instance(RelatedIdentifierCitationLabelService::class, $citationLabelService);

    foreach (['relation_discovery_running', 'orcid_discovery_running', 'ror_discovery_running'] as $lockKey) {
        Cache::lock($lockKey, 7200)->forceRelease();
    }

    Cache::flush();
});

function createRelationSuggestionForControllerTest(Resource $resource, string $identifier): SuggestedRelation
{
    $identifierType = IdentifierType::firstOrCreate(
        ['slug' => 'URL'],
        ['name' => 'URL', 'is_active' => true, 'is_elmo_active' => true],
    );

    $relationType = RelationType::firstOrCreate(
        ['slug' => 'References'],
        ['name' => 'References', 'is_active' => true, 'is_elmo_active' => true],
    );

    return SuggestedRelation::create([
        'resource_id' => $resource->id,
        'identifier' => $identifier,
        'identifier_type_id' => $identifierType->id,
        'relation_type_id' => $relationType->id,
        'source' => 'datacite_event_data',
        'source_title' => 'Suggested related work',
        'source_type' => 'Dataset',
        'source_publisher' => 'Example Publisher',
        'source_publication_date' => '2026',
        'discovered_at' => now(),
    ]);
}

// =========================================================================
// index
// =========================================================================

describe('index', function () {
    it('returns assistance page for authenticated user', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assistance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assistance')
                ->has('sections')
                ->has('manifests')
            );
    });

    it('includes the associated person name for affiliation ROR suggestions', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'family_name' => 'Curie',
            'given_name' => 'Marie',
        ]);
        $creator = ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);
        $affiliation = $creator->affiliations()->create([
            'name' => 'Sorbonne University',
            'identifier' => null,
            'identifier_scheme' => null,
            'scheme_uri' => null,
        ]);

        SuggestedRor::create([
            'resource_id' => $resource->id,
            'entity_type' => 'affiliation',
            'entity_id' => $affiliation->id,
            'entity_name' => 'Sorbonne University',
            'suggested_ror_id' => 'https://ror.org/02en5vm52',
            'suggested_name' => 'Sorbonne University',
            'similarity_score' => 0.98,
            'ror_aliases' => [],
            'existing_identifier' => null,
            'existing_identifier_type' => null,
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/assistance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.ror-suggestion.data.0.person_name', 'Curie, Marie')
            );
    });

    it('includes the associated person name for contributor affiliation ROR suggestions', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'family_name' => 'Einstein',
            'given_name' => 'Albert',
        ]);
        $contributor = ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => 1,
        ]);
        $affiliation = $contributor->affiliations()->create([
            'name' => 'ETH Zurich',
            'identifier' => null,
            'identifier_scheme' => null,
            'scheme_uri' => null,
        ]);

        SuggestedRor::create([
            'resource_id' => $resource->id,
            'entity_type' => 'affiliation',
            'entity_id' => $affiliation->id,
            'entity_name' => 'ETH Zurich',
            'suggested_ror_id' => 'https://ror.org/04bsj9r31',
            'suggested_name' => 'ETH Zurich',
            'similarity_score' => 0.98,
            'ror_aliases' => [],
            'existing_identifier' => null,
            'existing_identifier_type' => null,
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/assistance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.ror-suggestion.data.0.person_name', 'Einstein, Albert')
            );
    });

    it('rejects unauthenticated users', function () {
        $this->get('/assistance')
            ->assertRedirect('/login');
    });
});

// =========================================================================
// check (start discovery for single assistant)
// =========================================================================

describe('check', function () {
    it('starts discovery and returns job ID', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Use a real registered assistant (relation-suggestion)
        $response = $this->actingAs($user)
            ->post('/assistance/check/relation-suggestion')
            ->assertOk();

        $jobId = $response->json('jobId');
        expect($jobId)->toBeString()->toMatch('/^[a-f0-9-]{36}$/');
    });

    it('returns 404 for unknown assistant', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // unknown-assistant has no registered route, expect 404
        $this->actingAs($user)
            ->post('/assistance/check/unknown')
            ->assertNotFound();
    });

    it('returns 409 when lock already acquired', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Acquire the real lock for relation-suggestion
        $registrar = app(AssistantRegistrar::class);
        $assistant = $registrar->get('relation-suggestion');
        Cache::lock($assistant->getLockKey(), 7200)->get();

        $this->actingAs($user)
            ->post('/assistance/check/relation-suggestion')
            ->assertStatus(409);
    });
});

// =========================================================================
// status (poll job progress)
// =========================================================================

describe('status', function () {
    it('returns cached job status', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = \Illuminate\Support\Str::uuid()->toString();

        $registrar = app(AssistantRegistrar::class);
        $assistant = $registrar->get('relation-suggestion');
        $cacheKey = $assistant->getJobStatusCacheKey($jobId);

        Cache::put($cacheKey, [
            'status' => 'completed',
            'progress' => 'Done.',
            'newSuggestionsFound' => 3,
            'lockOwner' => 'secret-token',
        ], now()->addHour());

        $response = $this->actingAs($user)
            ->get("/assistance/check/relation-suggestion/{$jobId}/status")
            ->assertOk();

        $data = $response->json();
        expect($data['status'])->toBe('completed')
            ->and($data['newSuggestionsFound'])->toBe(3)
            // lockOwner should be stripped from response
            ->and($data)->not->toHaveKey('lockOwner');
    });

    it('returns 404 when job not found in cache', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = \Illuminate\Support\Str::uuid()->toString();

        $this->actingAs($user)
            ->get("/assistance/check/relation-suggestion/{$jobId}/status")
            ->assertNotFound();
    });
});

// =========================================================================
// accept
// =========================================================================

describe('accept', function () {
    it('returns not found for non-existent suggestion', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Suggestion 99999 doesn't exist, so acceptSuggestion returns failure
        $this->actingAs($user)
            ->post('/assistance/relations/99999/accept')
            ->assertOk()
            ->assertJson(['success' => false]);
    });
});

// =========================================================================
// decline
// =========================================================================

describe('decline', function () {
    it('returns success for non-existent suggestion', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Non-existent suggestion declines silently
        $this->actingAs($user)
            ->post('/assistance/relations/99999/decline', ['reason' => 'Not relevant'])
            ->assertOk()
            ->assertJson(['success' => true]);
    });
});

// =========================================================================
// batchRelations
// =========================================================================

describe('batchRelations', function () {
    it('accepts selected relation suggestions for one DOI', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->withDoi('10.14470/test-batch-accept')->create();
        $first = createRelationSuggestionForControllerTest($resource, 'https://example.org/related-1');
        $second = createRelationSuggestionForControllerTest($resource, 'https://example.org/related-2');

        $this->actingAs($user)
            ->post('/assistance/relations/batch/accept', [
                'suggestion_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'accepted_count' => 2,
                'skipped_count' => 0,
            ])
            ->assertJsonPath('message', 'Accepted 2 relation suggestion(s) for 10.14470/test-batch-accept: https://example.org/related-1, https://example.org/related-2.');

        expect(SuggestedRelation::query()->whereKey([$first->id, $second->id])->count())->toBe(0)
            ->and(RelatedIdentifier::query()->where('resource_id', $resource->id)->count())->toBe(2);
    });

    it('declines selected relation suggestions for one DOI', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->withDoi('10.14470/test-batch-decline')->create();
        $first = createRelationSuggestionForControllerTest($resource, 'https://example.org/declined-1');
        $second = createRelationSuggestionForControllerTest($resource, 'https://example.org/declined-2');

        $this->actingAs($user)
            ->post('/assistance/relations/batch/decline', [
                'suggestion_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'declined_count' => 2,
            ])
            ->assertJsonPath('message', 'Declined 2 relation suggestion(s) for 10.14470/test-batch-decline: https://example.org/declined-1, https://example.org/declined-2.');

        expect(SuggestedRelation::query()->whereKey([$first->id, $second->id])->count())->toBe(0)
            ->and(DismissedRelation::query()->where('resource_id', $resource->id)->count())->toBe(2);
    });

    it('rejects relation suggestion batches spanning multiple DOIs', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $firstResource = Resource::factory()->withDoi('10.14470/test-batch-one')->create();
        $secondResource = Resource::factory()->withDoi('10.14470/test-batch-two')->create();
        $first = createRelationSuggestionForControllerTest($firstResource, 'https://example.org/one');
        $second = createRelationSuggestionForControllerTest($secondResource, 'https://example.org/two');

        $this->actingAs($user)
            ->post('/assistance/relations/batch/accept', [
                'suggestion_ids' => [$first->id, $second->id],
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Relation suggestions can only be processed for one DOI at a time.',
            ]);

        expect(SuggestedRelation::query()->whereKey([$first->id, $second->id])->count())->toBe(2)
            ->and(RelatedIdentifier::query()->whereIn('resource_id', [$firstResource->id, $secondResource->id])->count())->toBe(0);
    });

    it('validates relation batch requests', function (): void {
   $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->postJson('/assistance/relations/batch/accept', [
            'suggestion_ids' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['suggestion_ids']);

    $this->actingAs($user)
        ->postJson('/assistance/relations/batch/accept', [
            'suggestion_ids' => ['not-an-id'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['suggestion_ids.0']);
    });

    it('rejects relation batch requests when a selected suggestion no longer exists', function (): void {
        $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->postJson('/assistance/relations/batch/accept', [
            'suggestion_ids' => [999999],
        ])
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'One or more selected relation suggestions are no longer available.',
        ]);
    });
});

// =========================================================================
// checkAll
// =========================================================================

describe('checkAll', function () {
    it('starts discovery for all assistants', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assistance/check-all')
            ->assertOk();

        $data = $response->json();
        // Should have jobId entries for registered assistants
        $hasJobIds = collect($data)->keys()->filter(fn ($k) => str_ends_with($k, 'JobId'))->count();
        expect($hasJobIds)->toBeGreaterThanOrEqual(1);
    });

    it('returns error info for locked assistants instead of silently skipping', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Lock only the relation-suggestion assistant
        $registrar = app(AssistantRegistrar::class);
        $relationAssistant = $registrar->get('relation-suggestion');
        Cache::lock($relationAssistant->getLockKey(), 7200)->get();

        $response = $this->actingAs($user)
            ->post('/assistance/check-all')
            ->assertOk();

        $data = $response->json();
        // Locked assistant should have an error entry
        expect($data)->toHaveKey('relation-suggestionError');
        // Other assistants should still get job IDs
        $hasJobIds = collect($data)->keys()->filter(fn ($k) => str_ends_with($k, 'JobId'))->count();
        expect($hasJobIds)->toBeGreaterThanOrEqual(1);
    });

    it('returns 409 with error entries when all assistants are already locked', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Lock all real assistants
        $registrar = app(AssistantRegistrar::class);
        foreach ($registrar->getAll() as $assistant) {
            Cache::lock($assistant->getLockKey(), 7200)->get();
        }

        $response = $this->actingAs($user)
            ->post('/assistance/check-all')
            ->assertStatus(409);

        $data = $response->json();
        // Should have per-assistant error entries plus the global error
        expect($data)->toHaveKey('error');
        $errorKeys = collect($data)->keys()->filter(fn ($k) => str_ends_with($k, 'Error'));
        expect($errorKeys->count())->toBeGreaterThanOrEqual(1);
    });
});
