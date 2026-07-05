<?php

declare(strict_types=1);

use App\DTOs\DateSuggestionPayload;

it('serializes the payload with the expected structure', function (): void {
    $payload = new DateSuggestionPayload(
        suggestion_kind: 'ADDITION',
        normalized_date_value: '2026-06-28',
        proposed_date_type: 'Created',
        confidence: 'HIGH',
        is_ambiguous: false,
    );

    expect($payload->suggestion_kind)->toBe('ADDITION')
        ->and($payload->normalized_date_value)->toBe('2026-06-28')
        ->and($payload->proposed_date_type)->toBe('Created')
        ->and($payload->confidence)->toBe('HIGH')
        ->and($payload->is_ambiguous)->toBeFalse()
        ->and($payload->toPayloadArray())->toBe([
            'suggestion_kind' => 'ADDITION',
            'normalized_date_value' => '2026-06-28',
            'proposed_date_type' => 'Created',
            'confidence' => 'HIGH',
            'is_ambiguous' => false,
        ]);
});

it('is implemented as a final readonly DTO', function (): void {
    $reflection = new ReflectionClass(DateSuggestionPayload::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
