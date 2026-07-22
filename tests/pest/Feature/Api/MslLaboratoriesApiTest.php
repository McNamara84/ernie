<?php

declare(strict_types=1);

use App\Http\Controllers\VocabularyController;
use App\Models\ThesaurusSetting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

covers(VocabularyController::class);

beforeEach(function (): void {
    config([
        'services.ernie.api_key' => 'test-api-key',
        'msl.laboratories_storage_path' => 'msl-laboratories.json',
    ]);
    Storage::fake('local');
});

function writeMslLaboratoriesVocabularyFixture(): void
{
    Storage::put('msl-laboratories.json', json_encode([
        'version' => '1.2',
        'lastUpdated' => '2026-07-21T12:00:00+00:00',
        'total' => 2,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => 'vocabularies/labs/1.2/laboratories.json',
            'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'data' => [
            [
                'identifier' => 'lab-001',
                'name' => 'Rock Physics Lab',
                'display_name' => 'Rock Physics Lab — GFZ',
                'affiliation_name' => 'GFZ Helmholtz Centre',
                'affiliation_ror' => 'https://ror.org/04z8jg394',
                'scientific_domain' => 'Geosciences',
                'country' => 'Germany',
            ],
            [
                'identifier' => 'lab-002',
                'name' => 'Independent Lab',
                'display_name' => 'Independent Lab — Utrecht',
                'affiliation_name' => 'Utrecht University',
                'affiliation_ror' => null,
                'scientific_domain' => 'Materials Science',
                'country' => 'Netherlands',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
}

function setMslLaboratoriesAvailability(bool $ernie, bool $elmo): void
{
    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_MSL_LABORATORIES],
        [
            'display_name' => 'MSL Laboratories',
            'is_active' => $ernie,
            'is_elmo_active' => $elmo,
        ]
    );
}

it('returns the public wrapper and all seven laboratory fields to ELMO', function (): void {
    writeMslLaboratoriesVocabularyFixture();
    setMslLaboratoriesAvailability(false, true);

    $response = getJson(
        '/api/v1/vocabularies/msl-laboratories',
        ['X-API-Key' => 'test-api-key']
    )->assertOk()
        ->assertJsonStructure([
            'version',
            'lastUpdated',
            'total',
            'data' => [
                '*' => [
                    'identifier',
                    'name',
                    'display_name',
                    'affiliation_name',
                    'affiliation_ror',
                    'scientific_domain',
                    'country',
                ],
            ],
        ]);

    expect(array_keys($response->json()))->toBe(['version', 'lastUpdated', 'total', 'data'])
        ->and($response->json('version'))->toBe('1.2')
        ->and($response->json('total'))->toBe(2)
        ->and($response->json('data.0.display_name'))->toBe('Rock Physics Lab — GFZ')
        ->and($response->json('data.1.affiliation_ror'))->toBeNull()
        ->and($response->json())->not->toHaveKey('source')
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
});

it('returns the same public wrapper to the authenticated ERNIE editor', function (): void {
    writeMslLaboratoriesVocabularyFixture();
    setMslLaboratoriesAvailability(true, false);
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)
        ->getJson('/vocabularies/msl-laboratories')
        ->assertOk();

    expect(array_keys($response->json()))->toBe(['version', 'lastUpdated', 'total', 'data'])
        ->and($response->json('data.0.identifier'))->toBe('lab-001')
        ->and($response->json())->not->toHaveKey('source');
});

it('requires authentication for the ERNIE editor endpoint', function (): void {
    writeMslLaboratoriesVocabularyFixture();

    getJson('/vocabularies/msl-laboratories')->assertUnauthorized();
});

it('returns 404 when laboratories are disabled for ELMO', function (): void {
    writeMslLaboratoriesVocabularyFixture();
    setMslLaboratoriesAvailability(true, false);

    getJson(
        '/api/v1/vocabularies/msl-laboratories',
        ['X-API-Key' => 'test-api-key']
    )->assertNotFound()
        ->assertJson(['error' => 'Thesaurus is disabled']);
});

it('returns 404 when laboratories are disabled for ERNIE', function (): void {
    writeMslLaboratoriesVocabularyFixture();
    setMslLaboratoriesAvailability(false, true);
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->getJson('/vocabularies/msl-laboratories')
        ->assertNotFound()
        ->assertJson(['error' => 'Thesaurus is disabled']);
});

it('returns 404 when the local vocabulary file is missing', function (): void {
    setMslLaboratoriesAvailability(true, true);

    getJson(
        '/api/v1/vocabularies/msl-laboratories',
        ['X-API-Key' => 'test-api-key']
    )->assertNotFound()
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-msl-laboratories',
        ]);
});

it('returns 500 when the local vocabulary file is corrupted', function (): void {
    setMslLaboratoriesAvailability(true, true);
    Storage::put('msl-laboratories.json', '{invalid-json');

    getJson(
        '/api/v1/vocabularies/msl-laboratories',
        ['X-API-Key' => 'test-api-key']
    )->assertInternalServerError()
        ->assertJson([
            'error' => 'The local MSL laboratories vocabulary contains invalid JSON.',
        ]);
});

it('returns 500 for a syntactically valid but semantically invalid local wrapper', function (string $field, mixed $value): void {
    setMslLaboratoriesAvailability(true, true);
    writeMslLaboratoriesVocabularyFixture();
    $payload = json_decode(Storage::get('msl-laboratories.json'), true, 512, JSON_THROW_ON_ERROR);

    if ($field === 'source.sha') {
        $payload['source']['sha'] = $value;
    } else {
        $payload[$field] = $value;
    }

    Storage::put('msl-laboratories.json', json_encode($payload, JSON_THROW_ON_ERROR));

    getJson(
        '/api/v1/vocabularies/msl-laboratories',
        ['X-API-Key' => 'test-api-key']
    )->assertInternalServerError();
})->with([
    'unstable version' => ['version', 'latest'],
    'invalid timestamp' => ['lastUpdated', 'not-a-date'],
    'invalid Git SHA' => ['source.sha', 'not-a-sha'],
    'mismatched total' => ['total', 99],
]);

it('reports MSL laboratories as available only when enabled and locally usable', function (): void {
    setMslLaboratoriesAvailability(true, true);

    getJson('/api/v1/vocabularies/thesauri-availability')
        ->assertOk()
        ->assertJsonPath('msl_laboratories.available', false);

    writeMslLaboratoriesVocabularyFixture();

    getJson('/api/v1/vocabularies/thesauri-availability')
        ->assertOk()
        ->assertJsonPath('msl_laboratories.available', true);

    Storage::put('msl-laboratories.json', '{broken');

    getJson('/api/v1/vocabularies/thesauri-availability')
        ->assertOk()
        ->assertJsonPath('msl_laboratories.available', false);
});

it('uses the independent ELMO toggle when reporting MSL availability', function (): void {
    setMslLaboratoriesAvailability(false, true);
    writeMslLaboratoriesVocabularyFixture();

    getJson('/api/v1/vocabularies/thesauri-availability')
        ->assertJsonPath('msl_laboratories.available', false);

    getJson('/api/v1/elmo/vocabularies/thesauri-availability', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonPath('msl_laboratories.available', true);
});

it('rejects missing and invalid ELMO API keys', function (?string $apiKey): void {
    writeMslLaboratoriesVocabularyFixture();

    $headers = $apiKey === null ? [] : ['X-API-Key' => $apiKey];

    getJson('/api/v1/vocabularies/msl-laboratories', $headers)
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
})->with([
    'missing key' => null,
    'invalid key' => 'wrong-key',
]);

it('authenticates ELMO before disclosing disabled or missing vocabulary state', function (): void {
    setMslLaboratoriesAvailability(false, false);
    Storage::assertMissing('msl-laboratories.json');

    getJson('/api/v1/vocabularies/msl-laboratories', ['X-API-Key' => 'wrong-key'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

it('returns 401 when the ELMO API key is not configured', function (): void {
    writeMslLaboratoriesVocabularyFixture();
    config(['services.ernie.api_key' => null]);

    getJson('/api/v1/vocabularies/msl-laboratories')
        ->assertUnauthorized()
        ->assertJson(['message' => 'API key not configured.']);
});
