<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Services\LandingPageMetadataLinkService;
use Database\Seeders\ResourceTestDataSeeder;
use Database\Seeders\ResourceTypeSeeder;

covers(ResourceTestDataSeeder::class);

it('seeds canonical datasets with public ISO metadata downloads', function (bool $seedLookupTables): void {
    if ($seedLookupTables) {
        $this->seed(ResourceTypeSeeder::class);
    }
    $originalTypeCount = ResourceType::count();
    Right::factory()->ccBy4()->create();

    $this->seed(ResourceTestDataSeeder::class);

    $landingPage = LandingPage::where('slug', 'mandatory-fields-only')->sole();
    $resource = $landingPage->resource;
    expect($resource->resourceType->slug)->toBe('dataset')
        ->and(ResourceType::count())->toBe($seedLookupTables ? $originalTypeCount : 1)
        ->and(Resource::where('resource_type_id', '!=', $resource->resource_type_id)->exists())->toBeFalse();

    $formats = array_column(app(LandingPageMetadataLinkService::class)->for($resource, $landingPage), 'format');
    expect($formats)->toContain('iso19115-3', 'iso19115-4');

    $this->get($landingPage->getPublicPath().'/metadata/iso-19115-4.json')
        ->assertOk()
        ->assertJsonPath('properties.identificationInfo.citation.title', 'TEST: Mandatory Fields Only');
})->with(['empty lookup tables' => false, 'production lookup tables' => true]);
