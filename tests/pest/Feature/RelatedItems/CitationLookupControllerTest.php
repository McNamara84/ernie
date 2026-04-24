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
            'titles' => [['title' => 'Great Paper', 'titleType' => 'MainTitle']],
            'creators' => [],
            'publicationYear' => 2023,
            'identifier' => '10.1234/abcd',
            'identifierType' => 'DOI',
        ]));
    $this->app->instance(CitationLookupService::class, $mock);

    $this->actingAs($user)
        ->getJson('/api/v1/citation-lookup?doi=10.1234/abcd')
        ->assertOk()
        ->assertJsonPath('source', 'crossref')
        ->assertJsonPath('found', true)
        ->assertJsonPath('data.titles.0.title', 'Great Paper')
        ->assertJsonPath('data.publicationYear', 2023);
});

test('returns notFound when the lookup service reports missing', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(CitationLookupService::class);
    $mock->shouldReceive('lookup')->once()->andReturn(CitationLookupResult::notFound('datacite'));
    $this->app->instance(CitationLookupService::class, $mock);

    $this->actingAs($user)
        ->getJson('/api/v1/citation-lookup?doi=10.1/missing')
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('source', 'datacite');
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
