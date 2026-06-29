<?php

use App\Services\DateType\DateSuggestionPayload;

test('date suggestion payload instantiates and converts correctly to array', function () {
    // 1. Arrange: Prepare mock data reflecting DataCite requirements
    $suggestionKind = 'CORRECTION';
    $normalizedValue = '1950/1980';
    $proposedType = 'Coverage';
    $confidence = 'HIGH';
    $isAmbiguous = false;

    // 2. Act: Instantiate the upgraded immutable DTO contract
    $payload = new DateSuggestionPayload(
        $suggestionKind,
        $normalizedValue,
        $proposedType,
        $confidence,
        $isAmbiguous
    );

    $resultArray = $payload->toPayloadArray();

    // 3. Assert: Verify the output strictly matches frontend API expectations
    expect($resultArray)->toBeArray()
        ->and($resultArray['suggestion_kind'])->toBe('CORRECTION')
        ->and($resultArray['normalized_date_value'])->toBe('1950/1980')
        ->and($resultArray['proposed_date_type'])->toBe('Coverage')
        ->and($resultArray['confidence'])->toBe('HIGH')
        ->and($resultArray['is_ambiguous'])->toBeFalse();
});