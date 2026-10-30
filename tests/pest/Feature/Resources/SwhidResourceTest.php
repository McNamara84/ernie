<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\User;
use App\Services\SchemaOrgJsonLdExporter;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seed([
        TitleTypeSeeder::class, DescriptionTypeSeeder::class,
        IdentifierTypeSeeder::class, RelationTypeSeeder::class,
        ResourceTypeSeeder::class, ContributorTypeSeeder::class,
        LanguageSeeder::class, PublisherSeeder::class,
    ]);
    $this->user = User::factory()->create();
    $right = Right::factory()->create();
    $datacenter = Datacenter::create(['name' => 'SWHID test datacenter']);
    $this->payload = [
        'year' => 2026, 'resourceType' => ResourceType::where('slug', 'dataset')->firstOrFail()->id,
        'accessLevel' => 'open', 'titles' => [['title' => 'SWHID test', 'titleType' => 'MainTitle']],
        'licenses' => [$right->identifier], 'datacenter_id' => $datacenter->id,
        'authors' => [['type' => 'person', 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'isContact' => false, 'affiliations' => [], 'position' => 0]],
        'descriptions' => [['descriptionType' => 'Abstract', 'description' => 'Archived software test.']],
    ];
    $this->swhid = 'swh:1:rel:f059f67da934b408306be0175c1f076590b68b7f';
});

it('normalizes resolver forms on both Validate and Save Draft without relying on blur', function (string $endpoint): void {
    $payload = $this->payload + ['relatedIdentifiers' => [[
        'identifier' => 'https://archive.softwareheritage.org/'.strtoupper($this->swhid).';origin=https://example.org/Mixed/',
        'identifierType' => 'SWHID', 'relationType' => 'IsIdenticalTo', 'citationLabel' => 'Manual citation',
    ]]];
    $this->actingAs($this->user)->postJson($endpoint, $payload)->assertSuccessful();
    $identifier = Resource::latest('id')->firstOrFail()->relatedIdentifiers()->sole();
    expect($identifier->identifier)->toBe($this->swhid.';origin=https://example.org/Mixed/')
        ->and($identifier->citation_label)->toBe('Manual citation');
    Http::assertNothingSent();
})->with(['/editor/resources', '/editor/resources/draft']);

it('rejects malformed SWHIDs on Validate but preserves their normalized draft values', function (): void {
    $invalid = substr($this->swhid, 0, -1);
    $payload = $this->payload + ['relatedIdentifiers' => [[
        'identifier' => 'https://n2t.net/'.$invalid, 'identifierType' => 'SWHID', 'relationType' => 'IsIdenticalTo',
    ]]];
    $this->actingAs($this->user)->postJson('/editor/resources', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('relatedIdentifiers.0.identifier');
    expect(Resource::count())->toBe(0);
    $this->postJson('/editor/resources/draft', $payload)->assertSuccessful();
    $resource = Resource::latest('id')->firstOrFail();
    expect($resource->relatedIdentifiers()->sole()->identifier)->toBe($invalid)
        ->and(app(SchemaOrgJsonLdExporter::class)->export($resource))->not->toHaveKey('identifier');
});

it('reports incomplete qualifiers against the exact entry without changing URL-typed references', function (): void {
    $payload = $this->payload + ['relatedIdentifiers' => [
        ['identifier' => 'https://archive.softwareheritage.org/'.$this->swhid, 'identifierType' => 'URL', 'relationType' => 'IsVariantFormOf', 'citationLabel' => 'Repository'],
        ['identifier' => $this->swhid.';anchor=swh:1:dir:'.str_repeat('a', 40), 'identifierType' => 'SWHID', 'relationType' => 'IsIdenticalTo'],
    ]];
    $this->actingAs($this->user)->postJson('/editor/resources', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors('relatedIdentifiers.1.identifier')->assertJsonMissingValidationErrors('relatedIdentifiers.0.identifier');
    $this->postJson('/editor/resources/draft', $payload)->assertSuccessful();
    $identifiers = Resource::latest('id')->firstOrFail()->relatedIdentifiers;
    expect($identifiers[0]->identifier)->toBe($payload['relatedIdentifiers'][0]['identifier'])
        ->and($identifiers[0]->identifierType->slug)->toBe('URL');
});

it('refreshes public JSON-LD after adding editing changing relation and removing SWHIDs', function (): void {
    config(['bot_protection.enabled' => true, 'bot_protection.landing_cache_ttl' => 600]);
    $this->actingAs($this->user)->postJson('/editor/resources', $this->payload)->assertSuccessful();
    $resource = Resource::latest('id')->firstOrFail();
    $resource->update(['doi' => '10.5880/swhid.test']);
    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id, 'doi_prefix' => $resource->doi, 'slug' => 'swhid-test',
    ]);
    $this->app['auth']->forgetGuards();
    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());
    $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
    $initial = $this->get($landingPage->getPublicPath())->assertOk();
    $linkHeader = $initial->headers->get('Link');
    $cached = $cache->get($cacheKey);
    expect($cached)->toBeArray();

    // Simulate a public read that repopulates the old data after the Resource
    // observer runs, but before the transaction replaces its related identifiers.
    Event::listen('eloquent.updated: '.Resource::class, function (Resource $updated) use ($resource, $cache, $cacheKey, &$cached): void {
        if ($updated->id === $resource->id) {
            $cache->put($cacheKey, $cached, 600);
        }
    });
    $qualified = $this->swhid.';origin=https://example.org/Mixed/';
    foreach ([[$this->swhid, 'IsIdenticalTo'], [$qualified, 'IsIdenticalTo'], [$qualified, 'IsVariantFormOf'], [null, null]] as $index => [$identifier, $relation]) {
        $references = $identifier === null ? [] : [[
            'identifier' => $identifier, 'identifierType' => 'SWHID', 'relationType' => $relation,
        ]];
        $payload = $this->payload + ['resourceId' => $resource->id, 'version' => (string) $index, 'relatedIdentifiers' => $references];
        $this->actingAs($this->user)->postJson('/editor/resources', $payload)->assertSuccessful();
        $this->app['auth']->forgetGuards();
        expect($cache->has($cacheKey))->toBeFalse();
        $response = $this->get($landingPage->getPublicPath())->assertOk()->assertHeader('Link', $linkHeader);
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $response->getContent(), $matches);
        $jsonLd = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        if ($relation === 'IsIdenticalTo') {
            expect($jsonLd['identifier'][0]['value'])->toBe('doi:10.5880/swhid.test')
                ->and($jsonLd['identifier'][1]['value'])->toBe($identifier)
                ->and($jsonLd['identifier'][1]['url'])->toBe('https://archive.softwareheritage.org/'.$identifier);
        } else {
            expect($jsonLd['identifier']['value'])->toBe('doi:10.5880/swhid.test');
        }
        $dataCite = $this->get($landingPage->getPublicPath().'/metadata/datacite.json')->assertOk();
        expect($dataCite->json('data.attributes.relatedIdentifiers') ?? [])->toBe($identifier === null ? [] : [[
            'relatedIdentifier' => $identifier, 'relatedIdentifierType' => 'SWHID', 'relationType' => $relation,
        ]]);
        $cached = $cache->get($cacheKey);
        expect($cached)->toBeArray();
    }
    Http::assertNothingSent();
});
