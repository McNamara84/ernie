<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use App\Services\LandingPageDocumentMetadataService;

covers(LandingPageDocumentMetadataService::class);

function resolveLandingPageDocumentMetadata(
    array $resourceData,
    bool $isPreview = false,
    string $template = LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
): array {
    return (new LandingPageDocumentMetadataService)->resolve($resourceData, $template, $isPreview);
}

it('uses the main title and public GFZ Data Services brand for published resources', function (): void {
    $metadata = resolveLandingPageDocumentMetadata([
        'titles' => [
            ['title' => 'Alternative title', 'title_type' => 'AlternativeTitle'],
            ['title' => 'The main dataset title', 'title_type' => 'MainTitle'],
            ['title' => 'A subtitle', 'title_type' => 'Subtitle'],
        ],
    ]);

    expect($metadata)->toBe([
        'title' => 'The main dataset title | GFZ Data Services',
        'robots' => null,
    ]);
});

it('marks previews in the title and excludes them from indexing', function (): void {
    $metadata = resolveLandingPageDocumentMetadata([
        'titles' => [['title' => 'Draft dataset', 'title_type' => 'MainTitle']],
    ], isPreview: true);

    expect($metadata)->toBe([
        'title' => 'Preview: Draft dataset | GFZ Data Services',
        'robots' => 'noindex, nofollow',
    ]);
});

it('accepts legacy main titles without a title type', function (): void {
    $metadata = resolveLandingPageDocumentMetadata([
        'titles' => [['title' => 'Legacy dataset title']],
    ]);

    expect($metadata['title'])->toBe('Legacy dataset title | GFZ Data Services');
});

it('falls back to Untitled when there is no usable main title', function (mixed $titles): void {
    $metadata = resolveLandingPageDocumentMetadata(['titles' => $titles]);

    expect($metadata['title'])->toBe('Untitled | GFZ Data Services');
})->with([
    'missing titles' => null,
    'empty titles' => [[]],
    'only a subtitle' => [[['title' => 'Subtitle only', 'title_type' => 'Subtitle']]],
    'empty main title' => [[['title' => '   ', 'title_type' => 'MainTitle']]],
    'malformed entries' => [['not-an-array', null]],
]);

it('uses the IGSN local name when the visible title falls back from tba', function (): void {
    $metadata = resolveLandingPageDocumentMetadata([
        'titles' => [['title' => ':tba', 'title_type' => 'MainTitle']],
        'igsn_metadata' => ['name' => '  Local sample ABC  '],
    ], template: LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG);

    expect($metadata['title'])->toBe('Local sample ABC | GFZ Data Services');
});

it('keeps tba for IGSNs without a usable local name', function (mixed $igsnMetadata): void {
    $metadata = resolveLandingPageDocumentMetadata([
        'titles' => [['title' => ':TBA', 'title_type' => 'MainTitle']],
        'igsn_metadata' => $igsnMetadata,
    ], template: LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG);

    expect($metadata['title'])->toBe(':TBA | GFZ Data Services');
})->with([
    'missing metadata' => null,
    'missing name' => [[]],
    'empty name' => [['name' => '   ']],
    'non-string name' => [['name' => 123]],
]);

it('does not apply the IGSN tba fallback to resource templates', function (): void {
    $metadata = resolveLandingPageDocumentMetadata([
        'titles' => [['title' => ':tba', 'title_type' => 'MainTitle']],
        'igsn_metadata' => ['name' => 'Local sample ABC'],
    ]);

    expect($metadata['title'])->toBe(':tba | GFZ Data Services');
});

it('preserves long titles and markup-like characters as plain metadata', function (): void {
    $title = 'Research <dataset> & "observations" '.str_repeat('x', 300);
    $metadata = resolveLandingPageDocumentMetadata([
        'titles' => [['title' => $title, 'title_type' => 'MainTitle']],
    ]);

    expect($metadata['title'])->toBe($title.' | GFZ Data Services')
        ->and(strlen($metadata['title']))->toBeGreaterThan(300);
});
