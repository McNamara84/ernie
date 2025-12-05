<?php

use App\Models\Subject;

/**
 * Unit tests for Subject model (DataCite #6)
 * These tests do not require database connections and can run in CI
 */
it('has correct fillable attributes', function (): void {
    $model = new Subject;

    $expectedFillable = [
        'resource_id',
        'subject',
        'subject_scheme',
        'scheme_uri',
        'value_uri',
        'classification_code',
        'language_id',
    ];

    expect($model->getFillable())->toBe($expectedFillable);
});

it('uses correct table name', function (): void {
    $model = new Subject;

    expect($model->getTable())->toBe('subjects');
});

it('can instantiate model without database', function (): void {
    $model = new Subject;

    expect($model)->toBeInstanceOf(Subject::class)
        ->and($model->exists)->toBeFalse();
});

it('has resource relationship method', function (): void {
    $model = new Subject;

    expect(method_exists($model, 'resource'))->toBeTrue();
});

it('has language relationship method', function (): void {
    $model = new Subject;

    expect(method_exists($model, 'language'))->toBeTrue();
});

it('accepts valid free-text subject data', function (): void {
    $model = new Subject;

    $validData = [
        'resource_id' => 1,
        'subject' => 'Geochemistry',
    ];

    $model->fill($validData);

    expect($model->resource_id)->toBe(1)
        ->and($model->subject)->toBe('Geochemistry')
        ->and($model->subject_scheme)->toBeNull()
        ->and($model->isFreeText())->toBeTrue()
        ->and($model->isControlled())->toBeFalse();
});

it('accepts valid controlled vocabulary subject data', function (): void {
    $model = new Subject;

    $validData = [
        'resource_id' => 1,
        'subject' => 'CALCIUM',
        'subject_scheme' => 'Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-1234-1234-123456789012',
        'classification_code' => 'EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM',
    ];

    $model->fill($validData);

    expect($model->resource_id)->toBe(1)
        ->and($model->subject)->toBe('CALCIUM')
        ->and($model->subject_scheme)->toBe('Science Keywords')
        ->and($model->scheme_uri)->toBe($validData['scheme_uri'])
        ->and($model->value_uri)->toBe($validData['value_uri'])
        ->and($model->classification_code)->toBe($validData['classification_code'])
        ->and($model->isControlled())->toBeTrue()
        ->and($model->isFreeText())->toBeFalse();
});

it('handles all GCMD scheme types correctly', function (string $scheme): void {
    $model = new Subject;

    $data = [
        'resource_id' => 1,
        'subject' => 'Test Subject',
        'subject_scheme' => $scheme,
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/test',
        'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
    ];

    $model->fill($data);

    expect($model->subject_scheme)->toBe($scheme)
        ->and($model->isControlled())->toBeTrue();
})->with(['Science Keywords', 'Platforms', 'Instruments']);

it('handles MSL vocabulary scheme', function (): void {
    $model = new Subject;

    $data = [
        'resource_id' => 1,
        'subject' => 'Laboratory Equipment',
        'subject_scheme' => 'msl',
        'scheme_uri' => 'https://epos-msl.uu.nl/voc/vocabulary',
        'value_uri' => 'https://epos-msl.uu.nl/voc/equipment/12345',
    ];

    $model->fill($data);

    expect($model->subject_scheme)->toBe('msl')
        ->and($model->isControlled())->toBeTrue();
});

it('stores full value URI correctly', function (): void {
    $model = new Subject;

    $valueUri = 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-5678-9012-123456789012';

    $data = [
        'resource_id' => 1,
        'subject' => 'Test',
        'subject_scheme' => 'Science Keywords',
        'value_uri' => $valueUri,
    ];

    $model->fill($data);

    expect($model->value_uri)->toBe($valueUri)
        ->and(strlen($model->value_uri))->toBeGreaterThan(36);
});

it('stores hierarchical classification code correctly', function (): void {
    $model = new Subject;

    $classificationCode = 'EARTH SCIENCE > AGRICULTURE > SOILS > CATION EXCHANGE CAPACITY';

    $data = [
        'resource_id' => 1,
        'subject' => 'CATION EXCHANGE CAPACITY',
        'subject_scheme' => 'Science Keywords',
        'classification_code' => $classificationCode,
    ];

    $model->fill($data);

    expect($model->classification_code)->toBe($classificationCode)
        ->and($model->classification_code)->toContain(' > ')
        ->and($model->subject)->toBe('CATION EXCHANGE CAPACITY');
});

it('handles scheme URIs for different keyword types', function (string $scheme, string $expectedSchemePattern): void {
    $model = new Subject;

    $schemeUri = "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/{$expectedSchemePattern}";

    $data = [
        'resource_id' => 1,
        'subject' => 'Test',
        'subject_scheme' => $scheme,
        'scheme_uri' => $schemeUri,
    ];

    $model->fill($data);

    expect($model->scheme_uri)->toContain($expectedSchemePattern);
})->with([
    ['Science Keywords', 'sciencekeywords'],
    ['Platforms', 'platforms'],
    ['Instruments', 'instruments'],
]);
