<?php

declare(strict_types=1);

use App\Services\ControlledSubjectImportNormalizerService;
use Illuminate\Support\Facades\Storage;

covers(ControlledSubjectImportNormalizerService::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('cgi-simple-lithology.json', json_encode([
        'data' => [[
            'id' => 'http://resource.geosciml.org/classifier/cgi/lithology/material',
            'text' => 'Material',
            'scheme' => 'CGI Simple Lithology',
            'schemeURI' => config('simple_lithology.scheme_uri'),
            'children' => [[
                'id' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt',
                'text' => 'Basalt',
                'scheme' => 'CGI Simple Lithology',
                'schemeURI' => config('simple_lithology.scheme_uri'),
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));
});

it('resolves an HTML-encoded path-only Simple Lithology subject', function (): void {
    $result = app(ControlledSubjectImportNormalizerService::class)->simpleLithology(
        'CGI Simple Lithology',
        'Material &gt; Basalt',
    );

    expect($result)->toMatchArray([
        'id' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt',
        'text' => 'Basalt',
        'path' => 'Material > Basalt',
        'scheme' => 'CGI Simple Lithology',
        'schemeURI' => 'http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology',
    ])->and($result)->not->toHaveKey('isLegacy');
});

it('preserves an unresolved path with a stable non-exportable UI identifier', function (): void {
    $service = app(ControlledSubjectImportNormalizerService::class);
    $first = $service->simpleLithology('CGI Simple Lithology', 'Material > Historical rock');
    $second = $service->simpleLithology('CGI Simple Lithology Vocabulary', 'Material > Historical rock');

    expect($first['id'])->toStartWith('legacy:')
        ->and($first['id'])->toBe($second['id'])
        ->and($first['isLegacy'])->toBeTrue()
        ->and($first['path'])->toBe('Material > Historical rock');
});

it('canonicalizes official concept and scheme URIs while rejecting foreign concept URIs', function (): void {
    $service = app(ControlledSubjectImportNormalizerService::class);
    $official = $service->simpleLithology(
        'CGI Simple Lithology',
        'Basalt',
        'https://example.test/untrusted-scheme',
        'https://resource.geosciml.org/classifier/cgi/lithology/basalt/',
    );
    $foreign = $service->simpleLithology(
        'CGI Simple Lithology',
        'Material > Foreign rock',
        null,
        'https://example.test/foreign-rock',
    );

    expect($official)->toMatchArray([
        'id' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt',
        'schemeURI' => 'http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology',
    ])->and($foreign['id'])->toStartWith('legacy:')
        ->and($foreign['isLegacy'])->toBeTrue();
});

it('does not claim unrelated controlled schemes', function (): void {
    expect(app(ControlledSubjectImportNormalizerService::class)->simpleLithology(
        'Local Lithology Terms',
        'Basalt',
    ))->toBeNull();
});
