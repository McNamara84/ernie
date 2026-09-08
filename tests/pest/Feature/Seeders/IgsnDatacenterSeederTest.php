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

it('assigns both system templates to every seeded datacenter', function (): void {
    $this->seed(DatacenterSeeder::class);
    $this->seed(LandingPageTemplateSeeder::class);

    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();

    Datacenter::query()->each(function (Datacenter $datacenter) use ($defaults): void {
        expect($datacenter->landing_page_template_id)->toBe($defaults['resource']->id)
            ->and($datacenter->igsn_landing_page_template_id)->toBe($defaults['igsn']->id);
    });
});

it('initializes a missing GFZ IGSN assignment when its resource slot already exists', function (): void {
    $this->seed(DatacenterSeeder::class);
    $this->seed(LandingPageTemplateSeeder::class);

    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();
    $gfz = Datacenter::query()->where('name', Datacenter::GFZ_NAME)->firstOrFail();
    $gfz->update([
        'landing_page_template_id' => $defaults['resource']->id,
        'igsn_landing_page_template_id' => null,
    ]);

    $this->seed(LandingPageTemplateSeeder::class);

    $gfz->refresh();
    expect($gfz->landing_page_template_id)->toBe($defaults['resource']->id)
        ->and($gfz->igsn_landing_page_template_id)->toBe($defaults['igsn']->id);
});

it('preserves custom Resource and IGSN assignments when the template seeder runs again', function (): void {
    $this->seed(DatacenterSeeder::class);
    $this->seed(LandingPageTemplateSeeder::class);

    $customResourceTemplate = LandingPageTemplate::factory()->create();
    $customIgsnTemplate = LandingPageTemplate::factory()->igsn()->create();
    $gfz = Datacenter::query()->where('name', Datacenter::GFZ_NAME)->firstOrFail();
    $gfz->update([
        'landing_page_template_id' => $customResourceTemplate->id,
        'igsn_landing_page_template_id' => $customIgsnTemplate->id,
    ]);

    $this->seed(LandingPageTemplateSeeder::class);

    $gfz->refresh();
    expect($gfz->landing_page_template_id)->toBe($customResourceTemplate->id)
        ->and($gfz->igsn_landing_page_template_id)->toBe($customIgsnTemplate->id);
});

it('repairs only missing assignments for every existing datacenter', function (): void {
    $this->seed(DatacenterSeeder::class);
    $this->seed(LandingPageTemplateSeeder::class);

    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();
    $customResourceTemplate = LandingPageTemplate::factory()->create();
    $customIgsnTemplate = LandingPageTemplate::factory()->igsn()->create();
    $resourceMissing = Datacenter::factory()->create([
        'igsn_landing_page_template_id' => $customIgsnTemplate->id,
    ]);
    $igsnMissing = Datacenter::factory()->create([
        'landing_page_template_id' => $customResourceTemplate->id,
    ]);
    $resourceMissing->update(['landing_page_template_id' => null]);
    $igsnMissing->update(['igsn_landing_page_template_id' => null]);

    $this->seed(LandingPageTemplateSeeder::class);

    expect($resourceMissing->fresh()?->landing_page_template_id)->toBe($defaults['resource']->id)
        ->and($resourceMissing->fresh()?->igsn_landing_page_template_id)->toBe($customIgsnTemplate->id)
        ->and($igsnMissing->fresh()?->landing_page_template_id)->toBe($customResourceTemplate->id)
        ->and($igsnMissing->fresh()?->igsn_landing_page_template_id)->toBe($defaults['igsn']->id);
});
