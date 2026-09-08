<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use App\Observers\DatacenterLandingPageTemplateAssignmentObserver;

covers(DatacenterLandingPageTemplateAssignmentObserver::class);

it('assigns both copy templates when a datacenter is created', function (): void {
    $datacenter = Datacenter::factory()->create();
    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();

    expect($datacenter->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
        ->and($datacenter->igsn_landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id);
});

it('fills only the missing assignment when a datacenter is created', function (string $type): void {
    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();
    $factory = LandingPageTemplate::factory();
    if ($type === LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
        $factory = $factory->igsn();
    }
    $custom = $factory->create();
    $foreignKey = LandingPageTemplate::datacenterAssignmentColumnForType($type);

    $datacenter = Datacenter::factory()->create([$foreignKey => $custom->id]);

    expect($datacenter->getAttribute($foreignKey))->toBe($custom->id)
        ->and($datacenter->landing_page_template_id)->not->toBeNull()
        ->and($datacenter->igsn_landing_page_template_id)->not->toBeNull()
        ->and($datacenter->getAttribute($foreignKey))->not->toBe($defaults[$type]->id);
})->with([
    'resource' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
    'IGSN' => LandingPageTemplate::TEMPLATE_TYPE_IGSN,
]);

it('applies defaults to datacenters created through firstOrCreate', function (): void {
    $datacenter = Datacenter::query()->firstOrCreate(['name' => 'New imported datacenter']);
    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();

    expect($datacenter->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
        ->and($datacenter->igsn_landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id);
});

it('restores both copy templates when an existing datacenter is saved without assignments', function (): void {
    $customResource = LandingPageTemplate::factory()->create();
    $customIgsn = LandingPageTemplate::factory()->igsn()->create();
    $datacenter = Datacenter::factory()->create([
        'landing_page_template_id' => $customResource->id,
        'igsn_landing_page_template_id' => $customIgsn->id,
    ]);

    $datacenter->forceFill([
        'landing_page_template_id' => null,
        'igsn_landing_page_template_id' => null,
    ])->save();

    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();

    expect($datacenter->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
        ->and($datacenter->igsn_landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id);
});
