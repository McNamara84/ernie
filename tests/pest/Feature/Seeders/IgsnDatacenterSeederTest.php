<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use App\Support\LegacyIgsnDatacenterCatalog;
use Database\Seeders\DatacenterSeeder;
use Database\Seeders\LandingPageTemplateSeeder;

it('seeds every canonical legacy IGSN datacenter without a duplicate GFZ Potsdam entry', function (): void {
    $this->seed(DatacenterSeeder::class);

    foreach (LegacyIgsnDatacenterCatalog::canonicalNames() as $name) {
        expect(Datacenter::query()->where('name', $name)->count())->toBe(1);
    }

    expect(Datacenter::query()->where('name', 'GFZ Potsdam')->exists())->toBeFalse();
});

it('assigns both GFZ system templates to their independent datacenter slots', function (): void {
    $this->seed(DatacenterSeeder::class);
    $this->seed(LandingPageTemplateSeeder::class);

    $gfz = Datacenter::query()->where('name', Datacenter::GFZ_NAME)->firstOrFail();
    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();

    expect($gfz->landing_page_template_id)->toBe($defaults['resource']->id)
        ->and($gfz->igsn_landing_page_template_id)->toBe($defaults['igsn']->id);
});

it('preserves a custom GFZ IGSN assignment when the template seeder runs again', function (): void {
    $this->seed(DatacenterSeeder::class);
    $this->seed(LandingPageTemplateSeeder::class);

    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();
    $customIgsnTemplate = LandingPageTemplate::factory()->igsn()->create();
    $gfz = Datacenter::query()->where('name', Datacenter::GFZ_NAME)->firstOrFail();
    $gfz->update([
        'landing_page_template_id' => null,
        'igsn_landing_page_template_id' => $customIgsnTemplate->id,
    ]);

    $this->seed(LandingPageTemplateSeeder::class);

    $gfz->refresh();
    expect($gfz->landing_page_template_id)->toBe($defaults['resource']->id)
        ->and($gfz->igsn_landing_page_template_id)->toBe($customIgsnTemplate->id);
});
