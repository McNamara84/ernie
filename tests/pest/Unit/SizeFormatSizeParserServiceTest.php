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
            'unit' => 'M',
            'type' => null,
        ]);
});

it('normalizes common size unit aliases while preserving unknown ones', function () {
    $service = app(SizeFormatSizeParserService::class);

    expect($service->parse('2K'))->toMatchArray([
        'numeric_value' => '2',
        'unit' => 'K',
        'type' => null,
    ])
        ->and($service->parse('3G'))->toMatchArray([
            'numeric_value' => '3',
            'unit' => 'G',
            'type' => null,
        ])
        ->and($service->parse('4T'))->toMatchArray([
            'numeric_value' => '4',
            'unit' => 'T',
            'type' => null,
        ])
        ->and($service->parse('5P'))->toMatchArray([
            'numeric_value' => '5',
            'unit' => 'P',
            'type' => null,
        ])
        ->and($service->parse('6B'))->toMatchArray([
            'numeric_value' => '6',
            'unit' => 'B',
            'type' => null,
        ])
        ->and($service->parse('7MiB'))->toMatchArray([
            'numeric_value' => '7',
            'unit' => 'MiB',
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
