<?php

declare(strict_types=1);

use App\Services\SizeFormat\SizeFormatSizeParserService;

covers(SizeFormatSizeParserService::class);

it('parses valid decimal size values with units', function () {
    $service = app(SizeFormatSizeParserService::class);

    expect($service->parse('8.1M'))->toMatchArray([
        'numeric_value' => '8.1',
        'unit' => 'M',
        'type' => null,
    ])
        ->and($service->parse('512 MB'))->toMatchArray([
            'numeric_value' => '512',
            'unit' => 'MB',
            'type' => null,
        ]);
});

it('keeps malformed decimal values unstructured', function () {
    $service = app(SizeFormatSizeParserService::class);

    expect($service->parse('1..2 MB'))->toMatchArray([
        'numeric_value' => null,
        'unit' => '1..2 MB',
        'type' => null,
    ])
        ->and($service->parse('1. MB'))->toMatchArray([
            'numeric_value' => null,
            'unit' => '1. MB',
            'type' => null,
        ]);
});

it('parses pure numeric values without inventing a unit', function () {
    $service = app(SizeFormatSizeParserService::class);

    expect($service->parse('250'))->toMatchArray([
        'numeric_value' => '250',
        'unit' => null,
        'type' => null,
    ]);
});
