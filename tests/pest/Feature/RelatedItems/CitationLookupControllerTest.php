<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Citations\CitationLookupResult;
use App\Services\Citations\CitationLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/citation-lookup?doi=10.1/x')
        ->assertStatus(401);
});

test('returns a hit with the transformed payload', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(CitationLookupService::class);
    $mock->shouldReceive('lookup')
        ->once()
        ->with('10.1234/abcd')
        ->andReturn(CitationLookupResult::hit('crossref', [
            'relatedItemType' => 'JournalArticle',
            'titles' => [
                ['title' => 'Great Paper', 'titleType' => 'MainTitle'],
                ['title' => 'A Subtitle', 'titleType' => 'Subtitle'],
            ],
            'creators' => [[
                'name' => 'Doe, Jane',
                'nameType' => 'Personal',
                'givenName' => 'Jane',
                'familyName' => 'Doe',
                'nameIdentifier' => '0000-0001-0002-0003',
                'nameIdentifierScheme' => 'ORCID',
            ]],
            'publicationYear' => 2023,
            'publisher' => 'Science Journal',
            'volume' => '12',
            'issue' => '3',
            'firstPage' => '101',
            'lastPage' => '115',
            'identifier' => '10.1234/abcd',
            'identifierType' => 'DOI',
        ]));
    $this->app->instance(CitationLookupService::class, $mock);

    $this->actingAs($user)
        ->getJson('/api/v1/citation-lookup?doi=10.1234/abcd')
        ->assertOk()
        ->assertJsonPath('source', 'crossref')
        ->assertJsonPath('identifier', '10.1234/abcd')
        ->assertJsonPath('identifier_type', 'DOI')
        ->assertJsonPath('related_item_type', 'JournalArticle')
        ->assertJsonPath('title', 'Great Paper')
        ->assertJsonPath('subtitle', 'A Subtitle')
        ->assertJsonPath('publication_year', 2023)
        ->assertJsonPath('publisher', 'Science Journal')
        ->assertJsonPath('volume', '12')
        ->assertJsonPath('issue', '3')
        ->assertJsonPath('first_page', '101')
        ->assertJsonPath('last_page', '115')
        ->assertJsonPath('creators.0.name', 'Doe, Jane')
        ->assertJsonPath('creators.0.name_type', 'Personal')
        ->assertJsonPath('creators.0.given_name', 'Jane')
        ->assertJsonPath('creators.0.family_name', 'Doe')
        ->assertJsonPath('creators.0.name_identifier', '0000-0001-0002-0003')
        ->assertJsonPath('creators.0.name_identifier_scheme', 'ORCID');
});

test('returns not_found when the lookup service reports missing', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(CitationLookupService::class);
    $mock->shouldReceive('lookup')->once()->andReturn(CitationLookupResult::notFound('datacite'));
    $this->app->instance(CitationLookupService::class, $mock);

    $this->actingAs($user)
        ->getJson('/api/v1/citation-lookup?doi=10.1/missing')
        ->assertOk()
        ->assertJsonPath('source', 'not_found')
        ->assertJsonPath('identifier', '10.1/missing')
        ->assertJsonPath('identifier_type', 'DOI')
        ->assertJsonMissingPath('title');
});

test('requires a DOI query parameter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/citation-lookup')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['doi']);
});

test('enforces the rate limit (30 req/min)', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(CitationLookupService::class);
    $mock->shouldReceive('lookup')->andReturn(CitationLookupResult::notFound('crossref'));
    $this->app->instance(CitationLookupService::class, $mock);

    // 30 requests should be allowed
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($user)
            ->getJson('/api/v1/citation-lookup?doi=10.1/x' . $i)
            ->assertOk();
    }

    // 31st should be throttled
    $this->actingAs($user)
        ->getJson('/api/v1/citation-lookup?doi=10.1/throttled')
        ->assertStatus(429);
});
