<?php

declare(strict_types=1);

use App\Services\Igsn\IgsnDescriptionNormalizerService;

covers(IgsnDescriptionNormalizerService::class);

it('normalizes every supported CSV description shape without splitting values', function (mixed $payload, array $expected): void {
    expect((new IgsnDescriptionNormalizerService)->normalizeCsvPayload($payload))->toBe($expected);
})->with([
    'single object' => [
        ['descriptions' => [
            ['description' => 'Core Oriented? 0; RQD Abundance: 0;', 'descriptionScheme' => ' Core Type '],
        ]],
        [['entries' => [
            ['value' => 'Core Oriented? 0; RQD Abundance: 0;', 'scheme' => 'Core Type'],
        ]]],
    ],
    'outer group list' => [
        [
            ['descriptions' => [['description' => 'black'], ['description' => 'Schist', 'descriptionScheme' => 'Rock Type']]],
            ['descriptions' => [['description' => 'white'], ['description' => 'Quartzite', 'descriptionScheme' => 'Rock Type']]],
        ],
        [
            ['entries' => [['value' => 'black', 'scheme' => null], ['value' => 'Schist', 'scheme' => 'Rock Type']]],
            ['entries' => [['value' => 'white', 'scheme' => null], ['value' => 'Quartzite', 'scheme' => 'Rock Type']]],
        ],
    ],
    'direct entry list' => [
        [['description' => 'one'], ['description' => 'two', 'descriptionScheme' => 'Kind']],
        [['entries' => [['value' => 'one', 'scheme' => null], ['value' => 'two', 'scheme' => 'Kind']]]],
    ],
]);

it('discards malformed empty and placeholder entries while keeping duplicates across groups', function (): void {
    $groups = (new IgsnDescriptionNormalizerService)->normalizeCsvPayload([
        ['descriptions' => [['description' => 'same'], ['description' => 'N/A'], ['description' => ' ']]],
        ['descriptions' => [['description' => 'same'], 'invalid']],
    ]);

    expect($groups)->toBe([
        ['entries' => [['value' => 'same', 'scheme' => null]]],
        ['entries' => [['value' => 'same', 'scheme' => null]]],
    ])->and((new IgsnDescriptionNormalizerService)->legacyValues($groups))->toBe(['same']);
});
