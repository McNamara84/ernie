<?php

declare(strict_types=1);

use App\Http\Controllers\AssistanceController;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\SuggestedRor;
use App\Models\User;
use App\Services\RorAffiliationBulkAcceptanceService;
use App\Services\RorDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

covers(AssistanceController::class, RorAffiliationBulkAcceptanceService::class);

beforeEach(function (): void {
    Config::set('cache.default', 'array');
    Cache::flush();
});

function createRorBulkRouteCreatorAffiliationSuggestion(
    string $familyName,
    string $givenName,
    string $affiliationName,
    string $rorId = 'https://ror.org/04z8jg394',
): array {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create([
        'family_name' => $familyName,
        'given_name' => $givenName,
    ]);
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    $affiliation = $creator->affiliations()->create([
        'name' => $affiliationName,
        'identifier' => null,
        'identifier_scheme' => null,
        'scheme_uri' => null,
    ]);
    $suggestion = SuggestedRor::create([
        'resource_id' => $resource->id,
        'entity_type' => 'affiliation',
        'entity_id' => $affiliation->id,
        'entity_name' => $affiliationName,
        'suggested_ror_id' => $rorId,
        'suggested_name' => 'GFZ German Research Centre for Geosciences',
        'similarity_score' => 0.98,
        'ror_aliases' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);

    return compact('resource', 'person', 'creator', 'affiliation', 'suggestion');
}

it('accepts matching affiliation rors from a valid bulk token through the route', function (): void {
    $user = User::factory()->admin()->create();
    $source = createRorBulkRouteCreatorAffiliationSuggestion('Einstein', 'Albert', 'Exact Observatory');
    $match = createRorBulkRouteCreatorAffiliationSuggestion('Einstein', 'Albert', 'Exact Observatory');

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);
    $bulkToken = $singleResult['bulk_affiliation_match']['bulk_token'];

    $this->actingAs($user)
        ->postJson('/assistance/rors/bulk-affiliation-accept', ['bulk_token' => $bulkToken])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('accepted_count', 1)
        ->assertJsonPath('skipped_count', 0);

    expect($match['affiliation']->refresh())
        ->identifier->toBe('https://ror.org/04z8jg394')
        ->identifier_scheme->toBe('ROR')
        ->scheme_uri->toBe('https://ror.org/')
        ->name->toBe('Exact Observatory')
        ->and(SuggestedRor::find($match['suggestion']->id))->toBeNull();
});

it('returns a validation-style failure for expired or invalid bulk tokens', function (): void {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->postJson('/assistance/rors/bulk-affiliation-accept', ['bulk_token' => 'missing-token'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('accepted_count', 0);
});

it('requires assistance access for the bulk route', function (): void {
    $user = User::factory()->beginner()->create();

    $this->actingAs($user)
        ->postJson('/assistance/rors/bulk-affiliation-accept', ['bulk_token' => 'missing-token'])
        ->assertForbidden();
});
