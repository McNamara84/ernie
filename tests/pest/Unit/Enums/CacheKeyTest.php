<?php

declare(strict_types=1);

use App\Enums\CacheKey;

it('generates correct cache keys without suffix', function () {
    expect(CacheKey::RESOURCE_LIST->key())->toBe('resources:list');
    expect(CacheKey::RESOURCE_DETAIL->key())->toBe('resources:detail');
    expect(CacheKey::GCMD_SCIENCE_KEYWORDS->key())->toBe('vocabularies:gcmd:science_keywords');
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
});

it('returns correct TTL for ROR and ORCID', function () {
    // ROR - 7 days (604800 seconds)
    expect(CacheKey::ROR_AFFILIATION->ttl())->toBe(604800);

    // ORCID - 24 hours (86400 seconds)
    expect(CacheKey::ORCID_PERSON->ttl())->toBe(86400);
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

it('all cache keys have unique values', function () {
    $cases = CacheKey::cases();
    $values = array_map(fn (CacheKey $key) => $key->value, $cases);

    expect($values)->toHaveCount(count(array_unique($values)));
});
