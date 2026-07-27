<?php

declare(strict_types=1);

use App\Http\Controllers\PublicMetadataExportController;
use App\Models\Description;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\User;
use App\Services\Iso19115\Iso19115XmlValidator;

covers(PublicMetadataExportController::class);

/**
 * @return array{0: Resource, 1: LandingPage}
 */
function publicMetadataResource(
    bool $published = true,
    string $doi = '10.5880/test.metadata.001',
    string $slug = 'metadata-test',
): array {
    $resource = Resource::factory()->create(['doi' => $doi]);
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Public metadata test',
    ]);
    Description::factory()->abstract()->create([
        'resource_id' => $resource->id,
        'value' => 'Metadata endpoint integration test.',
    ]);
    $factory = LandingPage::factory();
    $landingPage = ($published ? $factory->published() : $factory->draft())->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $doi,
        'slug' => $slug,
    ]);

    return [$resource->refresh(), $landingPage];
}

test('serves all canonical public metadata representations for a published landing page', function () {
    [, $landingPage] = publicMetadataResource();
    $base = $landingPage->getPublicPath().'/metadata';

    $this->get("{$base}/datacite.xml")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.datacite.datacite+xml; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="metadata-test-datacite.xml"')
        ->assertSee('http://datacite.org/schema/kernel-4', escape: false);

    $this->get("{$base}/datacite.json")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.datacite.datacite+json; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="metadata-test-datacite.json"')
        ->assertJsonPath('data.type', 'dois');

    $this->get("{$base}/datacite.jsonld")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/ld+json; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="metadata-test-datacite.jsonld"')
        ->assertJsonStructure(['@context', '@id']);

    $isoResponse = $this->get("{$base}/iso-19115-3.xml")
        ->assertOk()
        ->assertHeader('Content-Type', config('iso19115.media_type'))
        ->assertHeader('Content-Disposition', 'attachment; filename="metadata-test-iso-19115-3.xml"');

    $validation = app(Iso19115XmlValidator::class)->validate($isoResponse->getContent());
    expect($validation->isValid())->toBeTrue();
});

test('does not expose any metadata representation for draft landing pages', function (string $filename) {
    [, $landingPage] = publicMetadataResource(published: false);

    $this->get($landingPage->getPublicPath()."/metadata/{$filename}")->assertNotFound();
})->with([
    'DataCite XML' => 'datacite.xml',
    'DataCite JSON' => 'datacite.json',
    'DataCite JSON-LD' => 'datacite.jsonld',
    'ISO XML' => 'iso-19115-3.xml',
]);

test('selectively returns 404 for ISO on excluded resource types while retaining DataCite', function () {
    $projectType = ResourceType::firstOrCreate(
        ['slug' => 'project'],
        ['name' => 'Project', 'is_active' => true],
    );
    [$resource, $landingPage] = publicMetadataResource();
    $resource->update(['resource_type_id' => $projectType->id]);
    $base = $landingPage->getPublicPath().'/metadata';

    $this->get("{$base}/iso-19115-3.xml")->assertNotFound();
    $this->get("{$base}/datacite.xml")->assertOk();
});

test('feature flag removes only the ISO public representation', function () {
    [, $landingPage] = publicMetadataResource();
    config(['iso19115.enabled' => false]);
    $base = $landingPage->getPublicPath().'/metadata';

    $this->get("{$base}/iso-19115-3.xml")->assertNotFound();
    $this->get("{$base}/datacite.json")->assertOk();
});

test('public metadata endpoint rejects unknown DOI and slug combinations', function () {
    $this->get('/10.5880/unknown/missing/metadata/iso-19115-3.xml')->assertNotFound();
});

test('authenticated ISO export validates access, media type and eligible resources', function () {
    [$resource] = publicMetadataResource();
    $user = User::factory()->create();

    $this->get(route('resources.export-iso-19115-3', $resource))
        ->assertRedirect('/login');

    $response = $this->actingAs($user)->get(route('resources.export-iso-19115-3', $resource));
    $response->assertOk()
        ->assertHeader('Content-Type', config('iso19115.media_type'));
    expect($response->headers->get('Content-Disposition'))
        ->toContain("resource-{$resource->id}-")
        ->toContain('-iso-19115-3.xml');
    expect(app(Iso19115XmlValidator::class)->validate($response->getContent())->isValid())->toBeTrue();
});

test('authenticated ISO export returns 422 for excluded types', function () {
    $projectType = ResourceType::firstOrCreate(
        ['slug' => 'project'],
        ['name' => 'Project', 'is_active' => true],
    );
    [$resource] = publicMetadataResource();
    $resource->update(['resource_type_id' => $projectType->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('resources.export-iso-19115-3', $resource))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'ISO 19115-3 is not available for this resource type.');
});
