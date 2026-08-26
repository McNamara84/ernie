<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;

uses()->group('issue-1167', 'browser', 'landing-pages', 'igsn');

it('renders IGSN description labels from their schemes instead of the material', function (): void {
    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    $resource = Resource::factory()->create([
        'doi' => '10.60510/gfissue1167browser',
        'resource_type_id' => $physicalObject->id,
    ]);
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Issue 1167 IGSN description regression',
    ]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        'material' => 'Rock',
        'platform_type' => 'Drill Rig',
        'platform_name' => 'MSR Punto',
        'platform_description' => 'UDR',
        'description_json' => [
            'description_groups' => [
                ['entries' => [
                    ['value' => 'Core Oriented? 0; RQD Abundance: 0;', 'scheme' => null],
                    ['value' => 'Musc-bio schist', 'scheme' => 'Rock Type'],
                ]],
                ['entries' => [
                    ['value' => 'white', 'scheme' => null],
                    ['value' => 'Quartzite', 'scheme' => 'Rock Type'],
                ]],
            ],
            'material_descriptions' => [
                'Core Oriented? 0; RQD Abundance: 0;',
                'Musc-bio schist',
                'white',
                'Quartzite',
            ],
        ],
    ]);
    GeoLocation::create([
        'resource_id' => $resource->id,
        'place' => 'Northern drill site',
        'location_description' => 'General location',
        'locality_description' => 'Detailed locality',
    ]);
    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $resource->doi,
        'slug' => 'issue-1167-igsn-description-regression',
        'template' => 'default_gfz_igsn',
    ]);
    $browserUrl = parse_url($landingPage->public_url, PHP_URL_PATH);

    expect($browserUrl)->toBeString()->not->toBe('');

    $page = visit($browserUrl)
        ->waitForText('Acquisition')
        ->assertNoSmoke()
        ->assertSee('Core Oriented? 0; RQD Abundance: 0;')
        ->assertSee('Description')
        ->assertSee('Rock Type Description')
        ->assertDontSee('Rock Description')
        ->assertSee('Platform Description')
        ->assertSee('UDR')
        ->assertSee('Locality Description')
        ->assertSee('Detailed locality');

    expect($page->script('() => document.querySelectorAll(`[data-slot="igsn-description-group"]`).length'))
        ->toBe(2);
});
