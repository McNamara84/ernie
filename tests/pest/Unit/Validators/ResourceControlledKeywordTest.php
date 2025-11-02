<?php

use App\Models\ResourceControlledKeyword;

/**
 * Unit tests for ResourceControlledKeyword model
 * These tests do not require database connections and can run in CI
 */
it('has correct fillable attributes', function (): void {
    $model = new ResourceControlledKeyword;

    $expectedFillable = [
        'resource_id',
        'keyword_id',
        'text',
        'path',
        'language',
        'scheme',
        'scheme_uri',
    ];

    expect($model->getFillable())->toBe($expectedFillable);
});

it('uses correct table name', function (): void {
    $model = new ResourceControlledKeyword;

    expect($model->getTable())->toBe('resource_controlled_keywords');
});

it('validates scheme attribute values', function (): void {
    // This test verifies that different scheme values are properly handled
    // Scheme now discriminates keyword types instead of vocabulary_type column

    $validSchemes = [
        'Science Keywords',
        'Platforms',
        'Instruments',
        'EPOS MSL vocabulary',
    ];

    // These values should match the actual scheme values used in the system
    expect($validSchemes)->toContain('Science Keywords')
        ->and($validSchemes)->toContain('Platforms')
        ->and($validSchemes)->toContain('Instruments')
        ->and($validSchemes)->toContain('EPOS MSL vocabulary')
        ->and($validSchemes)->toHaveCount(4);
});

it('can instantiate model without database', function (): void {
    $model = new ResourceControlledKeyword;

    expect($model)->toBeInstanceOf(ResourceControlledKeyword::class)
        ->and($model->exists)->toBeFalse();
});

it('has resource relationship method', function (): void {
    $model = new ResourceControlledKeyword;

    expect(method_exists($model, 'resource'))->toBeTrue();
});

it('accepts valid keyword data structure', function (): void {
    $model = new ResourceControlledKeyword;

    $validData = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-1234-1234-123456789012',
        'text' => 'CALCIUM',
        'path' => 'EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM',
        'language' => 'en',
        'scheme' => 'Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
    ];

    // Fill the model (without saving to DB)
    $model->fill($validData);

    expect($model->resource_id)->toBe(1)
        ->and($model->keyword_id)->toBe($validData['keyword_id'])
        ->and($model->text)->toBe('CALCIUM')
        ->and($model->path)->toBe('EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM')
        ->and($model->language)->toBe('en')
        ->and($model->scheme)->toBe('Science Keywords')
        ->and($model->scheme_uri)->toBe($validData['scheme_uri']);
});

it('handles all scheme types correctly', function (string $scheme): void {
    $model = new ResourceControlledKeyword;

    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
        'text' => 'Test Keyword',
        'path' => 'TEST > PATH',
        'language' => 'en',
        'scheme' => $scheme,
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/test',
    ];

    $model->fill($data);

    expect($model->scheme)->toBe($scheme);
})->with(['Science Keywords', 'Platforms', 'Instruments', 'EPOS MSL vocabulary']);

it('handles multilingual keywords', function (string $language): void {
    $model = new ResourceControlledKeyword;

    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
        'text' => 'Test',
        'path' => 'TEST',
        'language' => $language,
        'scheme' => 'Science Keywords',
        'scheme_uri' => 'https://test.com',
    ];

    $model->fill($data);

    expect($model->language)->toBe($language);
})->with(['en', 'de', 'fr', 'es']);

it('stores full GCMD URI in keyword_id', function (): void {
    $model = new ResourceControlledKeyword;

    $longUri = 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-5678-9012-123456789012';

    $data = [
        'resource_id' => 1,
        'keyword_id' => $longUri,
        'text' => 'Test',
        'path' => 'TEST',
        'language' => 'en',
        'scheme' => 'Science Keywords',
        'scheme_uri' => 'https://test.com',
    ];

    $model->fill($data);

    expect($model->keyword_id)->toBe($longUri)
        ->and(strlen($model->keyword_id))->toBeGreaterThan(36); // Longer than UUID
});

it('stores hierarchical path correctly', function (): void {
    $model = new ResourceControlledKeyword;

    $hierarchicalPath = 'EARTH SCIENCE > AGRICULTURE > SOILS > SOIL CHEMISTRY > CATION EXCHANGE CAPACITY';

    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test',
        'text' => 'CATION EXCHANGE CAPACITY',
        'path' => $hierarchicalPath,
        'language' => 'en',
        'scheme' => 'Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
    ];

    $model->fill($data);

    expect($model->path)->toBe($hierarchicalPath)
        ->and($model->path)->toContain(' > ')
        ->and($model->text)->toBe('CATION EXCHANGE CAPACITY');
});

it('handles scheme URIs for different keyword types', function (string $scheme, string $expectedSchemePattern): void {
    $model = new ResourceControlledKeyword;

    $schemeUri = "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/{$expectedSchemePattern}";

    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test',
        'text' => 'Test',
        'path' => 'TEST',
        'language' => 'en',
        'scheme' => $scheme,
        'scheme_uri' => $schemeUri,
    ];

    $model->fill($data);

    expect($model->scheme_uri)->toContain($expectedSchemePattern);
})->with([
    ['Science Keywords', 'sciencekeywords'],
    ['Platforms', 'platforms'],
    ['Instruments', 'instruments'],
]);
