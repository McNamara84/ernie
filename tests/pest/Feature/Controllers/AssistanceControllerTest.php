<?php

declare(strict_types=1);

use App\Http\Controllers\AssistanceController;
use App\Models\AssistantSuggestion;
use App\Models\Datacenter;
use App\Models\DismissedRelation;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelatedItem;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedOrcid;
use App\Models\SuggestedRelation;
use App\Models\SuggestedRor;
use App\Models\User;
use App\Services\Assistance\AssistantContract;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

covers(AssistanceController::class);

beforeEach(function (): void {
    Config::set('cache.default', 'array');
    Queue::fake();

    foreach (['relation_discovery_running', 'orcid_discovery_running', 'ror_discovery_running'] as $lockKey) {
        Cache::lock($lockKey, 7200)->forceRelease();
    }

    Cache::flush();
});

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
                ->has('allAssistantResources')
                ->has('pendingCounts')
                ->has('manifests')
                ->where('assistanceCollapsedAssistantIds', null)
            );
    });

    it('exposes only registered collapsed Assistant IDs from the user profile', function () {
        /** @var AssistantRegistrar $registrar */
        $registrar = app(AssistantRegistrar::class);
        $assistantId = (string) array_key_first($registrar->getAll());
        $user = User::factory()->create([
            'role' => 'admin',
            'assistance_collapsed_assistant_ids' => [$assistantId, 'removed-assistant'],
        ]);

        $this->actingAs($user)
            ->get('/assistance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assistanceCollapsedAssistantIds', [$assistantId])
            );
    });

    it('orders active relation type options by related identifier usage and exposes relation type IDs on suggestions', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->create();
        $identifierType = IdentifierType::create([
            'name' => 'DOI',
            'slug' => 'DOI',
            'is_active' => true,
        ]);
        $relationTypes = collect([
            ['name' => 'Zulu Unused', 'slug' => 'ZuluUnused', 'count' => 0],
            ['name' => 'Has Part', 'slug' => 'HasPart', 'count' => 2],
            ['name' => 'Cites', 'slug' => 'Cites', 'count' => 5],
            ['name' => 'Alpha Unused', 'slug' => 'AlphaUnused', 'count' => 0],
            ['name' => 'Documents', 'slug' => 'Documents', 'count' => 3],
            ['name' => 'Compiles', 'slug' => 'Compiles', 'count' => 1],
            ['name' => 'References', 'slug' => 'References', 'count' => 4],
        ])->mapWithKeys(function (array $definition) use ($resource, $identifierType): array {
            $type = RelationType::create([
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'is_active' => true,
            ]);

            for ($position = 1; $position <= $definition['count']; $position++) {
                RelatedIdentifier::create([
                    'resource_id' => $resource->id,
                    'identifier' => "10.1234/{$definition['slug']}.{$position}",
                    'identifier_type_id' => $identifierType->id,
                    'relation_type_id' => $type->id,
                    'position' => $position,
                ]);
            }

            return [$definition['slug'] => $type];
        });

        $inactive = RelationType::create([
            'name' => 'Inactive Popular',
            'slug' => 'InactivePopular',
            'is_active' => false,
        ]);
        foreach (range(1, 8) as $position) {
            RelatedIdentifier::create([
                'resource_id' => $resource->id,
                'identifier' => "10.1234/inactive.{$position}",
                'identifier_type_id' => $identifierType->id,
                'relation_type_id' => $inactive->id,
                'position' => $position + 20,
            ]);
            RelatedItem::create([
                'resource_id' => $resource->id,
                'related_item_type' => 'Dataset',
                'relation_type_id' => $relationTypes['ZuluUnused']->id,
                'position' => $position,
            ]);
            SuggestedRelation::create([
                'resource_id' => $resource->id,
                'identifier' => "10.1234/open-suggestion.{$position}",
                'identifier_type_id' => $identifierType->id,
                'relation_type_id' => $relationTypes['ZuluUnused']->id,
                'source' => 'scholexplorer',
                'discovered_at' => now(),
            ]);
            DismissedRelation::create([
                'resource_id' => $resource->id,
                'identifier' => "10.1234/dismissed.{$position}",
                'relation_type_id' => $relationTypes['ZuluUnused']->id,
                'dismissed_by' => $user->id,
            ]);
        }

        $suggestion = SuggestedRelation::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/suggested',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationTypes['Cites']->id,
            'source' => 'scholexplorer',
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/assistance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('relationTypes', 7)
                ->where('relationTypes.0.slug', 'Cites')
                ->where('relationTypes.0.usage_count', 5)
                ->where('relationTypes.0.is_most_used', true)
                ->where('relationTypes.1.slug', 'References')
                ->where('relationTypes.2.slug', 'Documents')
                ->where('relationTypes.3.slug', 'HasPart')
                ->where('relationTypes.4.slug', 'Compiles')
                ->where('relationTypes.5.slug', 'AlphaUnused')
                ->where('relationTypes.5.is_most_used', false)
                ->where('relationTypes.6.slug', 'ZuluUnused')
                ->where('sections.relation-suggestion.data.0.suggestions.0.id', $suggestion->id)
                ->where('sections.relation-suggestion.data.0.suggestions.0.relation_type_id', $relationTypes['Cites']->id)
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
                ->where('sections.ror-suggestion.data.0.suggestions.0.person_name', 'Curie, Marie')
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
                ->where('sections.ror-suggestion.data.0.suggestions.0.person_name', 'Einstein, Albert')
            );
    });

    it('paginates complete resources and merges assistants in the all-assistants view', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $newerResource = Resource::factory()->create(['created_at' => now()]);
        $olderResource = Resource::factory()->create(['created_at' => now()->subDay()]);

        foreach ([1, 2] as $index) {
            AssistantSuggestion::create([
                'assistant_id' => 'date-type-suggestion',
                'resource_id' => $newerResource->id,
                'target_type' => 'date_type',
                'target_id' => $newerResource->id,
                'suggested_value' => 'Date '.$index,
                'suggested_label' => 'Date '.$index,
                'discovered_at' => now(),
            ]);
        }

        AssistantSuggestion::create([
            'assistant_id' => 'size-format-suggestion',
            'resource_id' => $newerResource->id,
            'target_type' => 'format',
            'target_id' => $newerResource->id,
            'suggested_value' => 'text/csv',
            'suggested_label' => 'CSV',
            'discovered_at' => now(),
        ]);
        AssistantSuggestion::create([
            'assistant_id' => 'date-type-suggestion',
            'resource_id' => $olderResource->id,
            'target_type' => 'date_type',
            'target_id' => $olderResource->id,
            'suggested_value' => 'Older date',
            'suggested_label' => 'Older date',
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/assistance?per_page=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.date-type-suggestion.total', 2)
                ->where('sections.date-type-suggestion.data.0.resource_id', $newerResource->id)
                ->has('sections.date-type-suggestion.data.0.suggestions', 2)
                ->where('allAssistantResources.total', 2)
                ->has('allAssistantResources.data.0.suggestions', 3)
                ->where('pendingCounts.date-type-suggestion', 3)
                ->where('pendingCounts.size-format-suggestion', 1)
            );
    });

    it('filters suggestions by indirect ORCID impact and keeps counts and datacenter options consistent', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $originDatacenter = Datacenter::factory()->create(['name' => 'Alpha Origin Center']);
        $affectedDatacenter = Datacenter::factory()->create(['name' => 'Beta Affected Center']);
        $origin = Resource::factory()->withDoi('10.5880/assistance.origin')->create([
            'datacenter_id' => $originDatacenter->id,
        ]);
        $affected = Resource::factory()->withDoi('10.5880/assistance.affected')->create([
            'datacenter_id' => $affectedDatacenter->id,
        ]);
        $person = Person::factory()->create();

        ResourceCreator::create([
            'resource_id' => $origin->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);
        ResourceContributor::create([
            'resource_id' => $affected->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => 1,
        ]);
        $orcidSuggestion = SuggestedOrcid::create([
            'resource_id' => $origin->id,
            'person_id' => $person->id,
            'suggested_orcid' => '0000-0001-5109-3700',
            'similarity_score' => 0.95,
            'candidate_first_name' => 'Jane',
            'candidate_last_name' => 'Doe',
            'candidate_affiliations' => [],
            'source_context' => 'creator',
            'discovered_at' => now(),
        ]);
        AssistantSuggestion::create([
            'assistant_id' => 'date-type-suggestion',
            'resource_id' => $origin->id,
            'target_type' => 'date_type',
            'target_id' => $origin->id,
            'suggested_value' => 'Created',
            'suggested_label' => 'Direct-only suggestion',
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/assistance?doi=https%3A%2F%2Fdoi.org%2F10.5880%2FASSISTANCE.AFFECTED&datacenter_id='.$affectedDatacenter->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.doi', '10.5880/assistance.affected')
                ->where('filters.datacenter_id', $affectedDatacenter->id)
                ->where('pendingCounts.orcid-suggestion', 1)
                ->where('pendingCounts.date-type-suggestion', 0)
                ->where('sections.orcid-suggestion.total', 1)
                ->where('sections.orcid-suggestion.data.0.resource_id', $origin->id)
                ->where('sections.orcid-suggestion.data.0.suggestions.0.id', $orcidSuggestion->id)
                ->where('sections.orcid-suggestion.data.0.suggestions.0.review.filter_match.kind', 'indirect')
                ->where('sections.orcid-suggestion.data.0.suggestions.0.review.filter_match.matched_resource_count', 1)
                ->where('sections.orcid-suggestion.data.0.suggestions.0.review.filter_match.matched_doi', '10.5880/assistance.affected')
                ->where('sections.date-type-suggestion.total', 0)
                ->where('allAssistantResources.total', 1)
                ->has('allAssistantResources.data.0.suggestions', 1)
                ->has('datacenterOptions', 2)
                ->where('datacenterOptions.0.name', 'Alpha Origin Center')
                ->where('datacenterOptions.1.name', 'Beta Affected Center')
            );

        $this->actingAs($user)
            ->get('/assistance?doi=https%3A%2F%2Fdoi.org%2F10.5880%2FASSISTANCE.ORIGIN&datacenter_id='.$originDatacenter->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.doi', '10.5880/assistance.origin')
                ->where('pendingCounts.orcid-suggestion', 1)
                ->where('pendingCounts.date-type-suggestion', 1)
                ->where('sections.orcid-suggestion.total', 1)
                ->where('sections.date-type-suggestion.total', 1)
                ->where('allAssistantResources.total', 1)
                ->has('allAssistantResources.data.0.suggestions', 2)
            );

        $this->actingAs($user)
            ->get('/assistance?doi=10.5880%2Fassistance.affected&datacenter_id='.$originDatacenter->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingCounts.orcid-suggestion', 0)
                ->where('sections.orcid-suggestion.total', 0)
                ->where('allAssistantResources.total', 0)
            );
    });

    it('keeps shared-entity impact filtering database-side for a large backlog', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $datacenter = Datacenter::factory()->create();
        $origin = Resource::factory()->withDoi('10.5880/backlog.origin')->create([
            'datacenter_id' => $datacenter->id,
        ]);
        $person = Person::factory()->create();

        ResourceCreator::create([
            'resource_id' => $origin->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);
        SuggestedOrcid::create([
            'resource_id' => $origin->id,
            'person_id' => $person->id,
            'suggested_orcid' => '0000-0001-5109-3700',
            'similarity_score' => 0.95,
            'candidate_first_name' => 'Jane',
            'candidate_last_name' => 'Doe',
            'candidate_affiliations' => [],
            'source_context' => 'creator',
            'discovered_at' => now(),
        ]);

        $resourceTemplate = $origin->getAttributes();
        unset($resourceTemplate['id']);
        $resourceRows = [];

        foreach (range(1, 250) as $index) {
            $resourceRows[] = [
                ...$resourceTemplate,
                'doi' => '10.5880/backlog.'.$index,
            ];
        }

        Resource::query()->insert($resourceRows);
        $affectedIds = Resource::query()
            ->where('doi', 'like', '10.5880/backlog.%')
            ->where('id', '!=', $origin->id)
            ->pluck('id');
        $now = now();
        ResourceContributor::query()->insert($affectedIds->map(static fn (int $resourceId): array => [
            'resource_id' => $resourceId,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $maximumBindingCount = 0;
        DB::listen(static function ($query) use (&$maximumBindingCount): void {
            $maximumBindingCount = max($maximumBindingCount, count($query->bindings));
        });

        $this->actingAs($user)
            ->get('/assistance?doi=10.5880%2Fbacklog.250&per_page=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingCounts.orcid-suggestion', 1)
                ->where('sections.orcid-suggestion.total', 1)
                ->has('sections.orcid-suggestion.data.0.suggestions', 1)
            );

        expect($maximumBindingCount)->toBeLessThan(100);
    });

    it('keeps active filters in assistance pagination links', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $datacenter = Datacenter::factory()->create();

        foreach (range(1, 2) as $index) {
            $resource = Resource::factory()->withDoi('10.5880/pagination.'.$index)->create([
                'datacenter_id' => $datacenter->id,
            ]);
            AssistantSuggestion::create([
                'assistant_id' => 'date-type-suggestion',
                'resource_id' => $resource->id,
                'target_type' => 'date_type',
                'target_id' => $resource->id,
                'suggested_value' => 'Created',
                'suggested_label' => 'Paginated suggestion '.$index,
                'discovered_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get('/assistance?datacenter_id='.$datacenter->id.'&per_page=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.date-type-suggestion.total', 2)
                ->where('sections.date-type-suggestion.per_page', 1)
                ->where('sections.date-type-suggestion.next_page_url', fn (string $url): bool => str_contains($url, 'datacenter_id='.$datacenter->id)
                    && str_contains($url, 'per_page=1'))
                ->where('allAssistantResources.next_page_url', fn (string $url): bool => str_contains($url, 'datacenter_id='.$datacenter->id)
                    && str_contains($url, 'per_page=1'))
            );
    });

    it('includes ROR institution suggestions when the filtered resource shares the institution', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $origin = Resource::factory()->withDoi('10.5880/ror.origin')->create();
        $affected = Resource::factory()->withDoi('10.5880/ror.affected')->create();
        $institution = Institution::factory()->create();

        foreach ([$origin, $affected] as $resource) {
            ResourceCreator::create([
                'resource_id' => $resource->id,
                'creatorable_type' => Institution::class,
                'creatorable_id' => $institution->id,
                'position' => 1,
            ]);
        }

        SuggestedRor::create([
            'resource_id' => $origin->id,
            'entity_type' => 'institution',
            'entity_id' => $institution->id,
            'entity_name' => $institution->name,
            'suggested_ror_id' => 'https://ror.org/012345678',
            'suggested_name' => $institution->name,
            'similarity_score' => 0.98,
            'ror_aliases' => [],
            'locations' => [],
            'existing_identifier' => null,
            'existing_identifier_type' => null,
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/assistance?doi=10.5880%2Fror.affected')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingCounts.ror-suggestion', 1)
                ->where('sections.ror-suggestion.total', 1)
                ->where('sections.ror-suggestion.data.0.resource_id', $origin->id)
                ->where('sections.ror-suggestion.data.0.suggestions.0.review.filter_match.kind', 'indirect')
                ->where('sections.ror-suggestion.data.0.suggestions.0.review.filter_match.matched_doi', '10.5880/ror.affected')
            );
    });

    it('rejects invalid assistance filter values', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->from('/assistance')
            ->get('/assistance?doi=not-a-doi')
            ->assertRedirect('/assistance')
            ->assertSessionHasErrors([
                'doi' => 'Enter a valid DOI in the format 10.xxxx/xxxxx or https://doi.org/10.xxxx/xxxxx.',
            ]);

        $this->actingAs($user)
            ->from('/assistance')
            ->get('/assistance?datacenter_id=999999')
            ->assertRedirect('/assistance')
            ->assertSessionHasErrors('datacenter_id');
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
        expect($jobId)->toBeUuid();
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
        $jobId = Str::uuid()->toString();

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
        $jobId = Str::uuid()->toString();

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

    it('forwards a validated active relation type override to the assistant', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $relationType = RelationType::create([
            'name' => 'References',
            'slug' => 'References',
            'is_active' => true,
        ]);
        $registrar = new AssistantRegistrar;
        $assistant = Mockery::mock(AssistantContract::class);
        $assistant->shouldReceive('getId')->once()->andReturn('relation-suggestion');
        $assistant->shouldReceive('countPending')->andReturn(0);
        $assistant->shouldReceive('acceptSuggestion')
            ->once()
            ->with(123, ['relation_type_id' => $relationType->id])
            ->andReturn(['success' => true, 'message' => 'Accepted with override.']);
        $registrar->register($assistant);
        app()->instance(AssistantRegistrar::class, $registrar);

        $this->actingAs($user)
            ->postJson('/assistance/relations/123/accept', ['relation_type_id' => $relationType->id])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Accepted with override.']);
    });
});

// =========================================================================
// decline
// =========================================================================

describe('decline', function () {
    it('returns failure for non-existent suggestion', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Non-existent suggestion declines silently
        $this->actingAs($user)
            ->post('/assistance/relations/99999/decline', ['reason' => 'Not relevant'])
            ->assertOk()
            ->assertJson(['success' => false]);
    });
});

describe('batch suggestions', function () {
    it('declines multiple resource-scoped suggestions through their assistants', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->create();
        $suggestions = collect([1, 2])->map(fn (int $index) => AssistantSuggestion::create([
            'assistant_id' => 'date-type-suggestion',
            'resource_id' => $resource->id,
            'target_type' => 'date_type',
            'target_id' => $resource->id,
            'suggested_value' => 'Hint '.$index,
            'suggested_label' => 'Hint '.$index,
            'metadata' => ['suggestion_kind' => 'hint'],
            'discovered_at' => now(),
        ]));

        $this->actingAs($user)
            ->postJson('/assistance/suggestions/batch/decline', [
                'resource_id' => $resource->id,
                'suggestions' => $suggestions->map(fn (AssistantSuggestion $suggestion) => [
                    'assistant_id' => 'date-type-suggestion',
                    'suggestion_id' => $suggestion->id,
                ])->all(),
                'reason' => 'Reviewed together',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'processed_count' => 2,
                'success_count' => 2,
                'failure_count' => 0,
            ])
            ->assertJsonCount(2, 'results');

        expect(AssistantSuggestion::whereKey($suggestions->pluck('id'))->exists())->toBeFalse();
    });

    it('rejects accepting a decline-only date hint without mutating it', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->create();
        $suggestion = AssistantSuggestion::create([
            'assistant_id' => 'date-type-suggestion',
            'resource_id' => $resource->id,
            'target_type' => 'date_type',
            'target_id' => $resource->id,
            'suggested_value' => 'Review the imported dates',
            'suggested_label' => 'Review the imported dates',
            'metadata' => ['suggestion_kind' => 'hint'],
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/assistance/suggestions/batch/accept', [
                'resource_id' => $resource->id,
                'suggestions' => [[
                    'assistant_id' => 'date-type-suggestion',
                    'suggestion_id' => $suggestion->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'At least one selected suggestion can only be declined.');

        expect($suggestion->fresh())->not->toBeNull();
    });

    it('rejects accepting two ORCID alternatives for the same person before either is mutated', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $resource = Resource::factory()->create();
        $person = Person::factory()->create();
        $suggestions = collect(['0000-0001-5109-3700', '0000-0002-1825-0097'])->map(
            fn (string $orcid, int $index) => SuggestedOrcid::create([
                'resource_id' => $resource->id,
                'person_id' => $person->id,
                'suggested_orcid' => $orcid,
                'similarity_score' => 0.9 - ($index * 0.1),
                'candidate_first_name' => 'Jane',
                'candidate_last_name' => 'Doe',
                'candidate_affiliations' => [],
                'source_context' => 'creator',
                'discovered_at' => now(),
            ]),
        );

        $this->actingAs($user)
            ->postJson('/assistance/suggestions/batch/accept', [
                'resource_id' => $resource->id,
                'suggestions' => $suggestions->map(fn (SuggestedOrcid $suggestion) => [
                    'assistant_id' => 'orcid-suggestion',
                    'suggestion_id' => $suggestion->id,
                ])->all(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only one alternative per target can be accepted.');

        expect(SuggestedOrcid::whereKey($suggestions->pluck('id'))->count())->toBe(2);
    });

    it('validates the resource and non-empty bounded selection payload', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->postJson('/assistance/suggestions/batch/accept', [
                'resource_id' => 0,
                'suggestions' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_id', 'suggestions']);
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
