<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Citations\CitationHtmlSanitizer;
use App\Services\Citations\LandingPageCitationService;
use App\Services\Citations\LandingPageCitationStyleRegistry;
use App\Services\Citations\LandingPageCslItemMapper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

covers(LandingPageCitationService::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function landingPageCitationServiceTestFixture(
    string $typeSlug = 'dataset',
    string $typeName = 'Dataset',
    array $overrides = [],
    string $titleValue = 'Crustal deformation observations',
    string $personFamilyName = 'Müller',
    string $institutionName = 'GFZ Helmholtz Centre',
    string $publisherName = 'GFZ Data Services',
): Resource {
    $resource = new Resource(array_merge([
        'doi' => '10.5880/example.2025.001',
        'publication_year' => 2025,
        'version' => '1.0',
    ], $overrides));
    $resource->setAttribute('id', 345);
    $resource->setRelation('resourceType', new ResourceType(['name' => $typeName, 'slug' => $typeSlug]));
    $resource->setRelation('publisher', new Publisher(['name' => $publisherName]));
    $resource->setRelation('language', new Language(['code' => 'en']));

    $titleType = new TitleType(['name' => 'Main Title', 'slug' => 'MainTitle']);
    $titleType->setAttribute('id', 1);
    $title = new Title([
        'value' => $titleValue,
        'title_type_id' => 1,
    ]);
    $title->setRelation('titleType', $titleType);
    $resource->setRelation('titles', new EloquentCollection([$title]));

    $personCreator = new ResourceCreator([
        'creatorable_type' => Person::class,
        'creatorable_id' => 1,
        'position' => 1,
    ]);
    $personCreator->setRelation('creatorable', new Person([
        'given_name' => 'Anna',
        'family_name' => $personFamilyName,
    ]));

    $institutionCreator = new ResourceCreator([
        'creatorable_type' => Institution::class,
        'creatorable_id' => 2,
        'position' => 2,
    ]);
    $institutionCreator->setRelation(
        'creatorable',
        new Institution(['name' => $institutionName]),
    );
    $resource->setRelation('creators', new EloquentCollection([$personCreator, $institutionCreator]));

    return $resource;
}

it('renders reviewable golden plaintext for all five official styles', function () {
    $service = app(LandingPageCitationService::class);
    $before = error_reporting();

    try {
        error_reporting(E_ALL);
        $styles = $service->format(landingPageCitationServiceTestFixture());

        expect(error_reporting())->toBe(E_ALL);
    } finally {
        error_reporting($before);
    }

    expect($styles)
        ->toHaveCount(5)
        ->and(array_column($styles, 'id'))->toBe([
            'apa-7',
            'harvard',
            'copernicus',
            'agu',
            'gsa',
        ])
        ->and(array_column($styles, 'text', 'id'))->toBe([
            'apa-7' => 'Müller, A., & GFZ Helmholtz Centre. (2025). Crustal deformation observations (Version 1.0) [Dataset]. GFZ Data Services. https://doi.org/10.5880/example.2025.001',
            'harvard' => 'Müller, A. and GFZ Helmholtz Centre (2025) ‘Crustal deformation observations’. GFZ Data Services. Available at: https://doi.org/10.5880/example.2025.001.',
            'copernicus' => 'Müller, A. and GFZ Helmholtz Centre: Crustal deformation observations (1.0), https://doi.org/10.5880/example.2025.001, 2025.',
            'agu' => 'Müller, A., & GFZ Helmholtz Centre. (2025). Crustal deformation observations (Version 1.0) [Data set]. GFZ Data Services. https://doi.org/10.5880/example.2025.001',
            'gsa' => 'Müller, A., and GFZ Helmholtz Centre, 2025, Crustal deformation observations:, doi:10.5880/example.2025.001.',
        ])
        ->and(array_column($styles, 'html', 'id'))->toBe([
            'apa-7' => '<div class="csl-entry csl-hanging-indent csl-double-spaced">Müller, A., &amp; GFZ Helmholtz Centre. (2025). <i>Crustal deformation observations</i> (Version 1.0) [Dataset]. GFZ Data Services. https://doi.org/10.5880/example.2025.001</div>',
            'harvard' => '<div class="csl-entry">Müller, A. and GFZ Helmholtz Centre (2025) ‘Crustal deformation observations’. GFZ Data Services. Available at: https://doi.org/10.5880/example.2025.001.</div>',
            'copernicus' => '<div class="csl-entry">Müller, A. and GFZ Helmholtz Centre: Crustal deformation observations (1.0), https://doi.org/10.5880/example.2025.001, 2025.</div>',
            'agu' => '<div class="csl-entry csl-hanging-indent csl-double-spaced">Müller, A., &amp; GFZ Helmholtz Centre. (2025). Crustal deformation observations (Version 1.0) [Data set]. GFZ Data Services. https://doi.org/10.5880/example.2025.001</div>',
            'gsa' => '<div class="csl-entry csl-hanging-indent">Müller, A., and GFZ Helmholtz Centre, 2025, Crustal deformation observations:, doi:10.5880/example.2025.001.</div>',
        ]);

    foreach ($styles as $style) {
        expect($style)
            ->toHaveKeys(['id', 'label', 'available', 'html', 'text'])
            ->available->toBeTrue()
            ->html->toStartWith('<div class="csl-entry')
            ->html->not->toContain('csl-bib-body')
            ->html->not->toContain('<script')
            ->text->not->toContain('<');
    }

    expect($styles[0]['html'])
        ->toContain('csl-hanging-indent')
        ->toContain('csl-double-spaced')
        ->toContain('<i>Crustal deformation observations</i>')
        ->and($styles[4]['html'])->toContain('csl-hanging-indent');
});

it('renders a physical object fixture through every official style', function () {
    $styles = app(LandingPageCitationService::class)->format(
        landingPageCitationServiceTestFixture('physical-object', 'Physical Object'),
    );

    foreach ($styles as $style) {
        expect($style)
            ->available->toBeTrue()
            ->text->toContain('Crustal deformation observations');
    }
});

it('omits DOI output without making any official style unavailable', function () {
    $styles = app(LandingPageCitationService::class)->format(
        landingPageCitationServiceTestFixture(overrides: ['doi' => null]),
    );

    foreach ($styles as $style) {
        expect($style)
            ->available->toBeTrue()
            ->text->not->toContain('doi.org')
            ->text->not->toContain('DOI not available');
    }
});

it('escapes hostile metadata end to end through mapper processor and sanitizer', function () {
    $styles = app(LandingPageCitationService::class)->format(
        landingPageCitationServiceTestFixture(
            titleValue: 'Crust <script>alert("title")</script> & Mantle',
            personFamilyName: 'Müller <img src=x onerror=alert(1)>',
            institutionName: 'GFZ <svg onload=alert(2)> Centre',
            publisherName: 'Publisher <iframe>payload</iframe>',
        ),
    );

    foreach ($styles as $style) {
        expect($style)
            ->available->toBeTrue()
            ->html->toContain('&lt;')
            ->html->not->toMatch('/<\s*(?:script|img|svg|iframe)\b/i')
            ->html->not->toMatch('/<[^>]+\s(?:onerror|onload)\s*=/i')
            ->text->toContain('<script>alert("title")</script>')
            ->text->toContain('<img src=x onerror=alert(1)>');
    }
});

it('isolates one broken style, logs structured context and restores error reporting', function () {
    $temporaryDirectory = storage_path('framework/testing/csl-'.Str::uuid());
    File::ensureDirectoryExists($temporaryDirectory);

    foreach (LandingPageCitationStyleRegistry::DEFINITIONS as $definition) {
        File::copy(
            base_path('resources/data/csl/styles/'.$definition['filename']),
            $temporaryDirectory.DIRECTORY_SEPARATOR.$definition['filename'],
        );
    }

    File::put(
        $temporaryDirectory.DIRECTORY_SEPARATOR.'apa.csl',
        '<?xml version="1.0"?><style xmlns="http://purl.org/net/xbiblio/csl" version="1.0"></style>',
    );

    $service = new LandingPageCitationService(
        new LandingPageCslItemMapper(),
        new CitationHtmlSanitizer(),
        new LandingPageCitationStyleRegistry($temporaryDirectory),
    );
    Log::spy();
    $before = error_reporting();

    try {
        error_reporting(E_ALL);
        $styles = $service->format(landingPageCitationServiceTestFixture());

        expect(error_reporting())->toBe(E_ALL);
    } finally {
        error_reporting($before);
        File::deleteDirectory($temporaryDirectory);
    }

    expect($styles[0])->toBe([
        'id' => 'apa-7',
        'label' => 'APA 7',
        'available' => false,
        'html' => null,
        'text' => null,
    ]);

    foreach (array_slice($styles, 1) as $style) {
        expect($style['available'])->toBeTrue();
    }

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Failed to render landing-page citation style.'
            && $context['resource_id'] === 345
            && $context['style_id'] === 'apa-7'
            && $context['exception'] instanceof Throwable);
});
