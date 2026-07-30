<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Http\Controllers\LandingPagePublicController;
use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Title;
use App\Services\LandingPageMachineMetadataService;
use App\Services\SchemaOrgJsonLdExporter;

covers(LandingPagePublicController::class, LandingPageMachineMetadataService::class);

/** @return array{0: Resource, 1: LandingPage} */
function machineMetadataLandingPage(string $resourceTypeSlug = 'dataset', array $landingPageAttributes = []): array
{
    $resourceType = ResourceType::firstOrCreate(
        ['slug' => $resourceTypeSlug],
        ['name' => $resourceTypeSlug === 'software' ? 'Software' : 'Dataset', 'is_active' => true],
    );
    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.machine.001',
        'resource_type_id' => $resourceType->id,
        'publication_year' => 2026,
        'access_level' => AccessLevel::OPEN,
    ]);
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Machine metadata test',
    ]);
    $person = Person::factory()->create([
        'given_name' => 'Ada',
        'family_name' => 'Lovelace',
    ]);
    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 0,
    ]);

    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $resource->doi,
        'slug' => 'machine-metadata-test',
        'template' => 'default_gfz',
        ...$landingPageAttributes,
    ]);

    return [$resource->refresh(), $landingPage];
}

/** @return array<string, mixed> */
function decodedEmbeddedSchemaOrg(string $html): array
{
    $matched = preg_match(
        '/<script type="application\/ld\+json">(.*?)<\/script>/s',
        $html,
        $matches,
    );

    expect($matched)->toBe(1);
    $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();

    return $decoded;
}

test('machine metadata contract exposes encoded JSON-LD without caching the source array', function () {
    [$resource, $landingPage] = machineMetadataLandingPage();

    $metadata = app(LandingPageMachineMetadataService::class)->for($resource, $landingPage);

    expect($metadata)
        ->toBeArray()
        ->toHaveKeys(['jsonLdJson', 'dublinCore', 'signpostingLinks', 'metadataLinks'])
        ->not->toHaveKey('jsonLd')
        ->and(json_decode($metadata['jsonLdJson'], true, 512, JSON_THROW_ON_ERROR))
        ->toBeArray()
        ->and(collect($metadata['dublinCore'])->where('name', 'DC.accessRights')->pluck('content')->all())
        ->toBe([
            AccessLevel::OPEN->label(),
            AccessLevel::OPEN->coarUri(),
        ]);
});

test('raw dataset HTML and GET HEAD responses expose complete machine metadata', function () {
    [$resource, $landingPage] = machineMetadataLandingPage(landingPageAttributes: [
        'ftp_url' => 'https://downloads.example.org/fallback.zip',
    ]);
    $format = $resource->formats()->create(['value' => 'application/pdf']);
    $size = $resource->sizes()->create(['numeric_value' => 2.5, 'unit' => 'MB']);
    $landingPage->files()->create([
        'url' => 'https://downloads.example.org/article.pdf',
        'position' => 0,
        'format_id' => $format->id,
        'size_id' => $size->id,
    ]);

    $right = Right::firstOrCreate(
        ['identifier' => 'CC-BY-4.0'],
        [
            'name' => 'Creative Commons Attribution 4.0 International',
            'uri' => 'https://creativecommons.org/licenses/by/4.0/',
            'scheme_uri' => 'https://spdx.org/licenses/',
        ],
    );
    $resource->rights()->attach($right->id);

    $response = $this->get($landingPage->getPublicPath())->assertOk();
    $html = $response->getContent();
    $jsonLd = decodedEmbeddedSchemaOrg($html);
    $linkHeader = $response->headers->get('Link');

    expect($jsonLd['@type'])->toBe('Dataset')
        ->and($jsonLd['@id'])->toBe('https://doi.org/10.5880/test.machine.001')
        ->and($jsonLd['url'])->toBe(url($landingPage->getPublicPath()))
        ->and($jsonLd['conditionsOfAccess'])->toBe('Open access')
        ->and($jsonLd['isAccessibleForFree'])->toBeTrue()
        ->and($jsonLd['distribution'])->toBe([
            [
                '@type' => 'DataDownload',
                'contentUrl' => 'https://downloads.example.org/article.pdf',
                'encodingFormat' => 'application/pdf',
                'contentSize' => '2500000',
            ],
        ])
        ->and($linkHeader)->toContain('<https://doi.org/10.5880/test.machine.001>; rel="cite-as"')
        ->toContain('<https://schema.org/Dataset>; rel="type"')
        ->toContain('<https://schema.org/AboutPage>; rel="type"')
        ->toContain('rel="describedby"; type="application/vnd.datacite.datacite+xml"')
        ->toContain('profile="'.config('iso19115.profile').'"')
        ->toContain('<https://spdx.org/licenses/CC-BY-4.0>; rel="license"')
        ->toContain('<https://downloads.example.org/article.pdf>; rel="item"; type="application/pdf"')
        ->and($html)->toContain('name="DC.identifier"')
        ->toContain('name="DC.title"')
        ->toContain('name="DC.creator"')
        ->toContain('name="DC.publisher"')
        ->toContain('name="DC.date"')
        ->toContain('name="DC.rights"')
        ->toContain('name="DC.type"')
        ->toContain('rel="cite-as"')
        ->toContain('rel="describedby"')
        ->toContain('rel="item"')
        ->toContain('data-profile="'.e(config('iso19115.profile')).'"')
        ->not->toContain(' profile="'.e(config('iso19115.profile')).'"');

    $response->assertInertia(fn ($page) => $page
        ->missing('schemaOrgJsonLd')
        ->has('metadataLinks', 4)
        ->where('metadataLinks.0.mediaType', 'application/vnd.datacite.datacite+xml')
        ->where('metadataLinks.1.mediaType', 'application/vnd.datacite.datacite+json')
    );

    $this->head($landingPage->getPublicPath())
        ->assertOk()
        ->assertHeader('Link', $linkHeader);
});

test('software JSON-LD exposes software types repository and direct downloads', function () {
    [$resource, $landingPage] = machineMetadataLandingPage('software', [
        'ftp_url' => 'https://downloads.example.org/software.zip',
    ]);
    $format = $resource->formats()->create(['value' => 'zip']);
    $size = $resource->sizes()->create(['numeric_value' => 4, 'unit' => 'MiB']);
    $landingPage->update([
        'ftp_format_id' => $format->id,
        'ftp_size_id' => $size->id,
    ]);
    $landingPage->links()->create([
        'url' => 'https://git.example.org/team/software',
        'label' => 'Source code',
        'kind' => LandingPageLink::KIND_REPOSITORY,
        'position' => 0,
    ]);

    $response = $this->get($landingPage->getPublicPath())->assertOk();
    $jsonLd = decodedEmbeddedSchemaOrg($response->getContent());
    $linkHeader = $response->headers->get('Link');

    expect($jsonLd['@type'])->toBe(['SoftwareSourceCode', 'SoftwareApplication'])
        ->and($jsonLd['codeRepository'])->toBe('https://git.example.org/team/software')
        ->and($jsonLd['downloadUrl'])->toBe('https://downloads.example.org/software.zip')
        ->and($jsonLd['associatedMedia'][0]['contentSize'])->toBe('4194304')
        ->and($jsonLd)->not->toHaveKey('distribution')
        ->and($linkHeader)->toContain('<https://schema.org/SoftwareSourceCode>; rel="type"')
        ->toContain('<https://downloads.example.org/software.zip>; rel="item"; type="application/zip"');
});

test('missing resource MIME data does not infer from the URL extension', function () {
    [, $landingPage] = machineMetadataLandingPage(landingPageAttributes: [
        'ftp_url' => 'https://downloads.example.org/data-with-obvious-extension.zip',
    ]);

    $response = $this->get($landingPage->getPublicPath())->assertOk();
    $jsonLd = decodedEmbeddedSchemaOrg($response->getContent());
    $linkHeader = $response->headers->get('Link');

    expect($jsonLd)->not->toHaveKey('distribution')
        ->and($linkHeader)->not->toContain('rel="item"')
        ->not->toContain('data-with-obvious-extension.zip');
});

test('an unassigned MIME descriptor falls back to the only valid resource format', function () {
    [$resource, $landingPage] = machineMetadataLandingPage(landingPageAttributes: [
        'ftp_url' => 'https://downloads.example.org/data.zip',
    ]);
    $resource->formats()->create(['value' => '.ZIP']);

    $response = $this->get($landingPage->getPublicPath())->assertOk();
    $jsonLd = decodedEmbeddedSchemaOrg($response->getContent());
    $linkHeader = $response->headers->get('Link');

    expect($jsonLd['distribution'])->toBe([[
        '@type' => 'DataDownload',
        'contentUrl' => 'https://downloads.example.org/data.zip',
        'encodingFormat' => 'application/zip',
    ]])
        ->and($linkHeader)->toContain(
            '<https://downloads.example.org/data.zip>; rel="item"; type="application/zip"',
        );
});

test('downloads unavailable suppresses JSON-LD and Signposting content links', function () {
    [$resource, $landingPage] = machineMetadataLandingPage(landingPageAttributes: [
        'ftp_url' => 'https://downloads.example.org/data.zip',
        'downloads_unavailable' => true,
    ]);
    $resource->formats()->create(['value' => 'application/zip']);

    $response = $this->get($landingPage->getPublicPath())->assertOk();
    $jsonLd = decodedEmbeddedSchemaOrg($response->getContent());

    expect($jsonLd)->not->toHaveKey('distribution')
        ->and($response->headers->get('Link'))->not->toContain('rel="item"');
});

test('JSON-LD encoding cannot be terminated by metadata containing script markup', function () {
    [$resource, $landingPage] = machineMetadataLandingPage();
    $dangerousTitle = 'Danger </script><script>alert(1)</script> — safe data';
    $resource->titles()->delete();
    Title::factory()->create(['resource_id' => $resource->id, 'value' => $dangerousTitle]);

    $response = $this->get($landingPage->getPublicPath())->assertOk();
    $html = $response->getContent();
    $jsonLd = decodedEmbeddedSchemaOrg($html);

    expect(substr_count($html, '<script type="application/ld+json">'))->toBe(1)
        ->and($html)->not->toContain('</script><script>alert(1)</script>')
        ->and($html)->toContain('\u003C/script\u003E')
        ->and($jsonLd['name'])->toBe($dangerousTitle);
});

test('invalid UTF-8 metadata is substituted instead of failing public rendering', function () {
    [, $landingPage] = machineMetadataLandingPage();
    $schemaOrgExporter = Mockery::mock(SchemaOrgJsonLdExporter::class);
    $schemaOrgExporter->shouldReceive('export')->once()->andReturn([
        '@context' => 'https://schema.org',
        '@type' => 'Dataset',
        'name' => "Invalid \xB1 title",
    ]);
    $this->app->instance(SchemaOrgJsonLdExporter::class, $schemaOrgExporter);

    $response = $this->get($landingPage->getPublicPath())->assertOk();
    $jsonLd = decodedEmbeddedSchemaOrg($response->getContent());

    expect($jsonLd['name'])->toBe("Invalid \u{FFFD} title");
});
