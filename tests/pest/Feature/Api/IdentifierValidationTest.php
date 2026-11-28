<?php

declare(strict_types=1);

use App\Http\Controllers\Api\IdentifierValidationController;
use App\Models\IdentifierType;
use App\Support\Swhid;
use Database\Seeders\IdentifierTypePatternSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

covers(IdentifierValidationController::class);

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'swhid-test-key']);
    Http::preventStrayRequests();
});

it('exposes the full shared validation contract without archive requests', function (): void {
    RateLimiter::for('identifier-validation', fn () => Limit::none());
    $fixtures = json_decode(file_get_contents(base_path('tests/fixtures/identifiers/swhid-cases.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixtures['cases'] as $case) {
        if ($case['input'] === '') {
            continue; // The transport requires an identifier; syntax failures otherwise return 200.
        }
        $response = $this->postJson('/api/v1/identifiers/validate', [
            'identifierType' => 'SWHID', 'identifier' => $case['input'],
        ], ['X-API-Key' => 'swhid-test-key'])->assertOk()
            ->assertJsonPath('identifierType', 'SWHID')
            ->assertJsonPath('profile', 'ernie-swhid-v1')
            ->assertJsonPath('valid', $case['valid'])
            ->assertJsonPath('normalized', $case['normalized'])
            ->assertJsonPath('resolverUrl', $case['resolverUrl']);

        expect($response->json('errors.0.code'))->toBe($case['error']);
        if ($case['valid']) {
            $decoded = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
            expect($decoded->qualifiers)->toBeInstanceOf(stdClass::class);
        } else {
            $response->assertJsonPath('core', null)->assertJsonPath('objectType', null)->assertJsonPath('qualifiers', null);
        }
    }
    Http::assertNothingSent();
});

it('accepts the existing Bearer key and rejects missing wrong or query-string credentials', function (): void {
    $input = ['identifierType' => 'SWHID', 'identifier' => 'swh:1:dir:'.str_repeat('a', 40)];
    $this->postJson('/api/v1/identifiers/validate', $input)->assertUnauthorized();
    $this->postJson('/api/v1/identifiers/validate', $input, ['X-API-Key' => 'wrong'])->assertUnauthorized();
    $this->postJson('/api/v1/identifiers/validate?api_key=swhid-test-key', $input)->assertUnauthorized();
    $this->postJson('/api/v1/identifiers/validate', $input, ['Authorization' => 'Bearer swhid-test-key'])->assertOk()->assertJsonPath('valid', true);
});

it('distinguishes malformed requests from negative syntax results', function (array $input, string $field): void {
    $this->postJson('/api/v1/identifiers/validate', $input, ['X-API-Key' => 'swhid-test-key'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [[], 'identifier'],
    [['identifier' => 'value'], 'identifierType'],
    [['identifierType' => 'DOI', 'identifier' => '10.1234/test'], 'identifierType'],
    [['identifierType' => 'SWHID', 'identifier' => []], 'identifier'],
    [['identifierType' => 'SWHID', 'identifier' => ''], 'identifier'],
    [['identifierType' => 'SWHID', 'identifier' => str_repeat('a', 2184)], 'identifier'],
]);

it('limits validation calls independently and resets for another IP', function (): void {
    $input = ['identifierType' => 'SWHID', 'identifier' => 'swh:1:dir:'.str_repeat('a', 40)];
    for ($index = 0; $index < 60; $index++) {
        $this->postJson('/api/v1/identifiers/validate', $input, ['X-API-Key' => 'swhid-test-key'])->assertOk();
    }
    $this->postJson('/api/v1/identifiers/validate', $input, ['X-API-Key' => 'swhid-test-key'])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->postJson('/api/v1/identifiers/validate', $input, ['X-API-Key' => 'swhid-test-key'])->assertOk();
});

it('advertises semantic validation on every identifier API while preserving activation and legacy fields', function (): void {
    $this->seed([IdentifierTypeSeeder::class, IdentifierTypePatternSeeder::class]);
    $type = IdentifierType::where('slug', 'SWHID')->firstOrFail();
    $type->update(['is_active' => true, 'is_elmo_active' => true, 'is_elmo_msl_active' => true]);
    foreach (['', '/ernie', '/elmo', '/elmo-msl'] as $suffix) {
        $response = $this->getJson('/api/v1/identifier-types'.$suffix, ['X-API-Key' => 'swhid-test-key'])->assertOk();
        $entries = collect($response->json())->keyBy('slug');
        expect($entries['SWHID']['semanticValidation'])->toBe([
            'method' => 'POST', 'endpoint' => '/api/v1/identifiers/validate', 'profile' => Swhid::PROFILE,
            'specificationVersion' => '1.2', 'requiresApiKey' => true,
        ])->and($entries['SWHID']['patterns']['validation'])->not->toBeEmpty()
            ->and($entries['DOI'])->not->toHaveKey('semanticValidation');
        if ($suffix === '/elmo') {
            expect($entries['SWHID']['pattern'])->toBeString();
        }
    }
    $type->update(['is_active' => false, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);
    foreach (['/ernie', '/elmo', '/elmo-msl'] as $suffix) {
        $this->getJson('/api/v1/identifier-types'.$suffix, ['X-API-Key' => 'swhid-test-key'])->assertOk()->assertJsonMissing(['slug' => 'SWHID']);
    }
});
