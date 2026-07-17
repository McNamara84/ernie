<?php

declare(strict_types=1);

use App\Enums\CacheKey;

covers(CacheKey::class);

it('generates correct cache keys without suffix', function () {
    expect(CacheKey::RESOURCE_LIST->key())->toBe('resources:list');
    expect(CacheKey::RESOURCE_DETAIL->key())->toBe('resources:detail');
    expect(CacheKey::GCMD_SCIENCE_KEYWORDS->key())->toBe('vocabularies:gcmd:science_keywords');
    expect(CacheKey::RAID_PROJECTS->key())->toBe('vocabularies:raid:projects');
    expect(CacheKey::ROR_AFFILIATION->key())->toBe('ror:affiliation');
});

it('generates correct cache keys with string suffix', function () {
    expect(CacheKey::RESOURCE_DETAIL->key('123'))->toBe('resources:detail:123');
    expect(CacheKey::ORCID_PERSON->key('0000-0001-2345-6789'))->toBe('orcid:person:0000-0001-2345-6789');
});

it('generates correct cache keys with integer suffix', function () {
    expect(CacheKey::RESOURCE_DETAIL->key(456))->toBe('resources:detail:456');
    expect(CacheKey::RESOURCE_COUNT->key(100))->toBe('resources:count:100');
});

it('versions landing page render data cache keys', function () {
    expect(CacheKey::LANDING_PAGE_RENDER_DATA->key())->toBe('landing_pages:render_data:v2')
        ->and(CacheKey::LANDING_PAGE_RENDER_DATA->key(123))->toBe('landing_pages:render_data:v2:123');
});

it('returns correct TTL for resources', function () {
    // Resource listings - 5 minutes (300 seconds)
    expect(CacheKey::RESOURCE_LIST->ttl())->toBe(300);
    expect(CacheKey::RESOURCE_COUNT->ttl())->toBe(300);

    // Individual resources - 15 minutes (900 seconds)
    expect(CacheKey::RESOURCE_DETAIL->ttl())->toBe(900);
});

it('returns correct TTL for vocabularies', function () {
    // Vocabularies - 24 hours (86400 seconds)
    expect(CacheKey::GCMD_SCIENCE_KEYWORDS->ttl())->toBe(86400);
    expect(CacheKey::GCMD_INSTRUMENTS->ttl())->toBe(86400);
    expect(CacheKey::GCMD_PLATFORMS->ttl())->toBe(86400);
    expect(CacheKey::GCMD_PROVIDERS->ttl())->toBe(86400);
    expect(CacheKey::MSL_KEYWORDS->ttl())->toBe(86400);
    expect(CacheKey::RAID_PROJECTS->ttl())->toBe(86400);
});

it('returns correct TTL for ROR and ORCID', function () {
    // ROR - 7 days (604800 seconds)
    expect(CacheKey::ROR_AFFILIATION->ttl())->toBe(604800);

    // ORCID - 24 hours (86400 seconds)
    expect(CacheKey::ORCID_PERSON->ttl())->toBe(86400);
});

it('returns configurable TTLs for public page payload caches', function () {
    config([
        'bot_protection.portal_cache_ttl' => 45,
        'bot_protection.landing_cache_ttl' => 90,
    ]);

    expect(CacheKey::PORTAL_PAGE_PAYLOAD->ttl())->toBe(45)
        ->and(CacheKey::LANDING_PAGE_RENDER_DATA->ttl())->toBe(90);
});

it('clamps public page payload cache TTLs to zero', function () {
    config([
        'bot_protection.portal_cache_ttl' => -10,
        'bot_protection.landing_cache_ttl' => -20,
    ]);

    expect(CacheKey::PORTAL_PAGE_PAYLOAD->ttl())->toBe(0)
        ->and(CacheKey::LANDING_PAGE_RENDER_DATA->ttl())->toBe(0);
});

it('returns correct tags for resources', function () {
    expect(CacheKey::RESOURCE_LIST->tags())->toBe(['resources']);
    expect(CacheKey::RESOURCE_DETAIL->tags())->toBe(['resources']);
    expect(CacheKey::RESOURCE_COUNT->tags())->toBe(['resources']);
});

it('returns correct tags for vocabularies', function () {
    expect(CacheKey::GCMD_SCIENCE_KEYWORDS->tags())->toBe(['vocabularies']);
    expect(CacheKey::GCMD_INSTRUMENTS->tags())->toBe(['vocabularies']);
    expect(CacheKey::MSL_KEYWORDS->tags())->toBe(['vocabularies']);
    expect(CacheKey::RAID_PROJECTS->tags())->toBe(['vocabularies']);
});

it('returns correct tags for ROR', function () {
    expect(CacheKey::ROR_AFFILIATION->tags())->toBe(['ror', 'affiliations']);
});

it('returns correct tags for ORCID', function () {
    expect(CacheKey::ORCID_PERSON->tags())->toBe(['orcid']);
});

it('returns correct tags for system', function () {
    expect(CacheKey::CACHE_STATS->tags())->toBe(['system']);
});

it('returns correct tags for public page payload caches', function () {
    expect(CacheKey::PORTAL_PAGE_PAYLOAD->tags())->toBe(['portal_page_payloads'])
        ->and(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->toBe(['resources', 'landing_pages']);
});

it('returns correct tags for assessment summary metrics', function () {
    expect(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->tags())->toBe(['assessments']);
});

it('all cache keys have unique values', function () {
    $cases = CacheKey::cases();
    $values = array_map(fn (CacheKey $key) => $key->value, $cases);

    expect($values)->toHaveCount(count(array_unique($values)));
});

it('returns all vocabulary keys', function () {
    $vocabularyKeys = CacheKey::vocabularyKeys();

    expect($vocabularyKeys)->toBeArray()
        ->toHaveCount(11)
        ->each->toBeInstanceOf(CacheKey::class);

    $expectedKeys = [
        CacheKey::GCMD_SCIENCE_KEYWORDS,
        CacheKey::GCMD_INSTRUMENTS,
        CacheKey::GCMD_PLATFORMS,
        CacheKey::GCMD_PROVIDERS,
        CacheKey::MSL_KEYWORDS,
        CacheKey::PID4INST_INSTRUMENTS,
        CacheKey::RAID_PROJECTS,
        CacheKey::CHRONOSTRAT_TIMESCALE,
        CacheKey::GEMET_THESAURUS,
        CacheKey::ANALYTICAL_METHODS,
        CacheKey::EUROSCIVOC,
    ];

    expect($vocabularyKeys)->toEqual($expectedKeys);
});

it('vocabulary keys all have vocabularies tag', function () {
    foreach (CacheKey::vocabularyKeys() as $key) {
        expect($key->tags())->toContain('vocabularies');
    }
});

it('vocabulary keys do not include non-vocabulary keys', function () {
    $vocabularyKeys = CacheKey::vocabularyKeys();

    expect($vocabularyKeys)->not->toContain(CacheKey::RESOURCE_LIST)
        ->not->toContain(CacheKey::ROR_AFFILIATION)
        ->not->toContain(CacheKey::ORCID_PERSON)
        ->not->toContain(CacheKey::CACHE_STATS);
});
