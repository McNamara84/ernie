<?php

declare(strict_types=1);

use App\Services\Igsn\IgsnSampleImageUrlService;
use Illuminate\Support\Facades\Config;

covers(IgsnSampleImageUrlService::class);

beforeEach(function (): void {
    Config::set('igsn_images.gfz', [
        'host' => 'dataservices.gfz-potsdam.de',
        'path_prefix' => '/extern/IGSN/',
    ]);
    Config::set('igsn_images.icdp', [
        'legacy_host' => 'www-icdp.icdp-online.org',
        'canonical_host' => 'data.icdp-online.org',
        'path_prefixes' => ['/sites/cosc/', '/sites/dfdp/', '/sites/lusklint/', '/sites/sustain/'],
    ]);
});

it('classifies the Sonne image source as managed', function (): void {
    $result = app(IgsnSampleImageUrlService::class)->resolve(
        'https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/',
        'SO273-31D-18_wet.jpg',
    );

    expect($result)->toMatchArray([
        'status' => 'managed',
        'source_url' => 'https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/SO273-31D-18_wet.jpg',
        'external_url' => null,
    ]);
});

it('normalizes only known ICDP hosts and path families', function (): void {
    $result = app(IgsnSampleImageUrlService::class)->resolve(
        'http://www-icdp.icdp-online.org/sites/cosc/news/cores/',
        'CS 5054.jpg',
    );

    expect($result)->toMatchArray([
        'status' => 'external',
        'source_url' => 'http://www-icdp.icdp-online.org/sites/cosc/news/cores/CS%205054.jpg',
        'external_url' => 'https://data.icdp-online.org/sites/cosc/news/cores/CS%205054.jpg',
    ])->and(app(IgsnSampleImageUrlService::class)->resolve(
        'https://www-icdp.icdp-online.org/sites/unknown/',
        'sample.jpg',
    )['status'])->toBe('unsupported');
});

it('rejects placeholders traversal credentials query strings ports and unknown hosts', function (string $base, string $file, string $status): void {
    expect(app(IgsnSampleImageUrlService::class)->resolve($base, $file)['status'])->toBe($status);
})->with([
    'placeholder' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/A/', 'NN', 'missing'],
    'traversal' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/A/', '../secret.jpg', 'unsupported'],
    'encoded traversal' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/A/', '..%2Fsecret.jpg', 'unsupported'],
    'base traversal' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/a/../../private/', 'sample.jpg', 'unsupported'],
    'encoded base traversal' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/a/%2e%2e/%2e%2e/private/', 'sample.jpg', 'unsupported'],
    'double encoded base traversal' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/a/%252e%252e/private/', 'sample.jpg', 'unsupported'],
    'encoded base separator' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/a%2fprivate/', 'sample.jpg', 'unsupported'],
    'encoded base backslash' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/a%5cprivate/', 'sample.jpg', 'unsupported'],
    'encoded base nul' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/a%00private/', 'sample.jpg', 'unsupported'],
    'credentials' => ['https://user:pass@dataservices.gfz-potsdam.de/extern/IGSN/A/', 'sample.jpg', 'unsupported'],
    'query' => ['https://dataservices.gfz-potsdam.de/extern/IGSN/A/?download=1', 'sample.jpg', 'unsupported'],
    'port' => ['https://dataservices.gfz-potsdam.de:8443/extern/IGSN/A/', 'sample.jpg', 'unsupported'],
    'host' => ['https://example.org/extern/IGSN/A/', 'sample.jpg', 'unsupported'],
]);

it('reports unsafe configured-host base paths explicitly', function (): void {
    $result = app(IgsnSampleImageUrlService::class)->resolve(
        'https://dataservices.gfz-potsdam.de/extern/IGSN/a/%2e%2e/private/',
        'sample.jpg',
    );

    expect($result)->toMatchArray([
        'status' => 'unsupported',
        'reason' => 'invalid_base_path',
    ]);
});

it('reclassifies stored source URLs without broad host rewriting', function (): void {
    $service = app(IgsnSampleImageUrlService::class);

    expect($service->classifySourceUrl('https://dataservices.gfz-potsdam.de/extern/IGSN/A/sample.jpg')['status'])->toBe('managed')
        ->and($service->classifySourceUrl('https://example.org/sample.jpg')['status'])->toBe('unsupported');
});
