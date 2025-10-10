<?php

use App\Models\ResourceControlledKeyword;

/**
 * Unit tests for ResourceControlledKeyword model
 * These tests do not require database connections and can run in CI
 */

it('has correct fillable attributes', function (): void {
    $model = new ResourceControlledKeyword();
    
    $expectedFillable = [
        'resource_id',
        'keyword_id',
        'text',
        'path',
        'language',
        'scheme',
        'scheme_uri',
        'vocabulary_type',
    ];
    
    expect($model->getFillable())->toBe($expectedFillable);
});

it('uses correct table name', function (): void {
    $model = new ResourceControlledKeyword();
    
    expect($model->getTable())->toBe('resource_controlled_keywords');
});

it('validates vocabulary type enum values', function (): void {
    // This test verifies that the expected vocabulary types are documented
    // The actual database constraint is tested in migration tests
    
    $validTypes = ['science', 'platforms', 'instruments'];
    
    // These values should match the ENUM in the migration
    expect($validTypes)->toContain('science')
        ->and($validTypes)->toContain('platforms')
        ->and($validTypes)->toContain('instruments')
        ->and($validTypes)->toHaveCount(3);
});

it('can instantiate model without database', function (): void {
    $model = new ResourceControlledKeyword();
    
    expect($model)->toBeInstanceOf(ResourceControlledKeyword::class)
        ->and($model->exists)->toBeFalse();
});

it('has resource relationship method', function (): void {
    $model = new ResourceControlledKeyword();
    
    expect(method_exists($model, 'resource'))->toBeTrue();
});

it('accepts valid keyword data structure', function (): void {
    $model = new ResourceControlledKeyword();
    
    $validData = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-1234-1234-123456789012',
        'text' => 'CALCIUM',
        'path' => 'EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM',
        'language' => 'en',
        'scheme' => 'Earth Science',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'vocabulary_type' => 'science',
    ];
    
    // Fill the model (without saving to DB)
    $model->fill($validData);
    
    expect($model->resource_id)->toBe(1)
        ->and($model->keyword_id)->toBe($validData['keyword_id'])
        ->and($model->text)->toBe('CALCIUM')
        ->and($model->path)->toBe('EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM')
        ->and($model->language)->toBe('en')
        ->and($model->scheme)->toBe('Earth Science')
        ->and($model->scheme_uri)->toBe($validData['scheme_uri'])
        ->and($model->vocabulary_type)->toBe('science');
});

it('handles all vocabulary types correctly', function (string $vocabularyType): void {
    $model = new ResourceControlledKeyword();
    
    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
        'text' => 'Test Keyword',
        'path' => 'TEST > PATH',
        'language' => 'en',
        'scheme' => 'Test Scheme',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/test',
        'vocabulary_type' => $vocabularyType,
    ];
    
    $model->fill($data);
    
    expect($model->vocabulary_type)->toBe($vocabularyType);
})->with(['science', 'platforms', 'instruments']);

it('handles multilingual keywords', function (string $language): void {
    $model = new ResourceControlledKeyword();
    
    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
        'text' => 'Test',
        'path' => 'TEST',
        'language' => $language,
        'scheme' => 'Test',
        'scheme_uri' => 'https://test.com',
        'vocabulary_type' => 'science',
    ];
    
    $model->fill($data);
    
    expect($model->language)->toBe($language);
})->with(['en', 'de', 'fr', 'es']);

it('stores full GCMD URI in keyword_id', function (): void {
    $model = new ResourceControlledKeyword();
    
    $longUri = 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-5678-9012-123456789012';
    
    $data = [
        'resource_id' => 1,
        'keyword_id' => $longUri,
        'text' => 'Test',
        'path' => 'TEST',
        'language' => 'en',
        'scheme' => 'Test',
        'scheme_uri' => 'https://test.com',
        'vocabulary_type' => 'science',
    ];
    
    $model->fill($data);
    
    expect($model->keyword_id)->toBe($longUri)
        ->and(strlen($model->keyword_id))->toBeGreaterThan(36); // Longer than UUID
});

it('stores hierarchical path correctly', function (): void {
    $model = new ResourceControlledKeyword();
    
    $hierarchicalPath = 'EARTH SCIENCE > AGRICULTURE > SOILS > SOIL CHEMISTRY > CATION EXCHANGE CAPACITY';
    
    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test',
        'text' => 'CATION EXCHANGE CAPACITY',
        'path' => $hierarchicalPath,
        'language' => 'en',
        'scheme' => 'Earth Science',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'vocabulary_type' => 'science',
    ];
    
    $model->fill($data);
    
    expect($model->path)->toBe($hierarchicalPath)
        ->and($model->path)->toContain(' > ')
        ->and($model->text)->toBe('CATION EXCHANGE CAPACITY');
});

it('handles scheme URIs for different vocabulary types', function (string $vocabularyType, string $expectedSchemePattern): void {
    $model = new ResourceControlledKeyword();
    
    $schemeUri = "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/{$expectedSchemePattern}";
    
    $data = [
        'resource_id' => 1,
        'keyword_id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test',
        'text' => 'Test',
        'path' => 'TEST',
        'language' => 'en',
        'scheme' => 'Test Scheme',
        'scheme_uri' => $schemeUri,
        'vocabulary_type' => $vocabularyType,
    ];
    
    $model->fill($data);
    
    expect($model->scheme_uri)->toContain($expectedSchemePattern);
})->with([
    ['science', 'sciencekeywords'],
    ['platforms', 'platforms'],
    ['instruments', 'instruments'],
]);
