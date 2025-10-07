<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.elmo.api_key' => null,
        'services.ernie.api_key' => null,
    ]);

    Role::create([
        'name' => 'Author',
        'slug' => 'author',
        'applies_to' => Role::APPLIES_TO_AUTHOR,
        'is_active_in_ernie' => true,
        'is_active_in_elmo' => true,
    ]);

    Role::create([
        'name' => 'Inactive Author',
        'slug' => 'inactive-author',
        'applies_to' => Role::APPLIES_TO_AUTHOR,
        'is_active_in_ernie' => false,
        'is_active_in_elmo' => true,
    ]);

    Role::create([
        'name' => 'Researcher',
        'slug' => 'researcher',
        'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        'is_active_in_ernie' => true,
        'is_active_in_elmo' => true,
    ]);

    Role::create([
        'name' => 'Rights Holder',
        'slug' => 'rights-holder',
        'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION,
        'is_active_in_ernie' => true,
        'is_active_in_elmo' => true,
    ]);

    Role::create([
        'name' => 'Hosting Institution',
        'slug' => 'hosting-institution',
        'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        'is_active_in_ernie' => true,
        'is_active_in_elmo' => true,
    ]);

    Role::create([
        'name' => 'Inactive Institution Role',
        'slug' => 'inactive-institution-role',
        'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        'is_active_in_ernie' => false,
        'is_active_in_elmo' => true,
    ]);
});

dataset('ernie-role-uris', [
    '/api/v1/roles/authors/ernie',
    '/api/v1/roles/contributor-persons/ernie',
    '/api/v1/roles/contributor-institutions/ernie',
]);

dataset('ernie-role-uris-with-count', [
    ['/api/v1/roles/authors/ernie', 1],
    ['/api/v1/roles/contributor-persons/ernie', 2],
    ['/api/v1/roles/contributor-institutions/ernie', 2],
]);

test('returns author roles active for Ernie', function () {
    $response = getJson('/api/v1/roles/authors/ernie')->assertOk();

    expect($response->json())->toBe([
        ['id' => Role::whereSlug('author')->value('id'), 'name' => 'Author', 'slug' => 'author'],
    ]);
});

it('rejects ernie role requests without an API key when one is configured', function (string $uri) {
    config(['services.ernie.api_key' => 'ernie-secret']);

    getJson($uri)
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
})->with('ernie-role-uris');

it('rejects ernie role requests with an invalid API key', function (string $uri) {
    config(['services.ernie.api_key' => 'ernie-secret']);

    getJson($uri, ['X-API-Key' => 'wrong-secret'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
})->with('ernie-role-uris');

it('allows ernie role requests with a valid API key header', function (string $uri, int $expectedCount) {
    config(['services.ernie.api_key' => 'ernie-secret']);

    getJson($uri, ['X-API-Key' => 'ernie-secret'])
        ->assertOk()
        ->assertJsonCount($expectedCount);
})->with('ernie-role-uris-with-count');

it('allows ernie role requests with a valid API key query parameter', function (string $uri, int $expectedCount) {
    config(['services.ernie.api_key' => 'ernie-secret']);

    getJson("{$uri}?api_key=ernie-secret")
        ->assertOk()
        ->assertJsonCount($expectedCount);
})->with('ernie-role-uris-with-count');

test('returns contributor person roles active for Ernie', function () {
    $response = getJson('/api/v1/roles/contributor-persons/ernie')->assertOk();

    expect($response->json())->toBe([
        ['id' => Role::whereSlug('researcher')->value('id'), 'name' => 'Researcher', 'slug' => 'researcher'],
        ['id' => Role::whereSlug('rights-holder')->value('id'), 'name' => 'Rights Holder', 'slug' => 'rights-holder'],
    ]);
});

test('returns contributor institution roles active for Ernie', function () {
    $response = getJson('/api/v1/roles/contributor-institutions/ernie')->assertOk();

    expect($response->json())->toBe([
        [
            'id' => Role::whereSlug('hosting-institution')->value('id'),
            'name' => 'Hosting Institution',
            'slug' => 'hosting-institution',
        ],
        [
            'id' => Role::whereSlug('rights-holder')->value('id'),
            'name' => 'Rights Holder',
            'slug' => 'rights-holder',
        ],
    ]);
});
