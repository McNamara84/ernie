<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\LandingPageTemplateResolverService;

covers(LandingPageTemplateResolverService::class);

beforeEach(function (): void {
    $this->resolver = app(LandingPageTemplateResolverService::class);
    $this->datasetType = ResourceType::query()->firstOrCreate(
        ['slug' => 'dataset'],
        ['name' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true],
    );
    $this->physicalObjectType = ResourceType::query()->firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true, 'is_elmo_active' => true],
    );
    $this->defaults = LandingPageTemplate::ensureSystemTemplatesExist();
});

it('inherits a regular template from the resource datacenter', function (): void {
    $template = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create(['landing_page_template_id' => $template->id]);
    $resource = Resource::factory()->create([
        'resource_type_id' => $this->datasetType->id,
        'datacenter_id' => $datacenter->id,
    ]);

    $resolved = $this->resolver->automatic($resource);

    expect($resolved['template']->is($template))->toBeTrue()
        ->and($resolved['source'])->toBe(LandingPageTemplateResolverService::SOURCE_DATACENTER);
});

it('prefers an explicit custom template over the datacenter template', function (): void {
    $inherited = LandingPageTemplate::factory()->create();
    $explicit = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create(['landing_page_template_id' => $inherited->id]);
    $resource = Resource::factory()->create([
        'resource_type_id' => $this->datasetType->id,
        'datacenter_id' => $datacenter->id,
    ]);
    $landingPage = LandingPage::factory()->for($resource)->create([
        'template' => LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
        'landing_page_template_id' => $explicit->id,
    ]);

    $resolved = $this->resolver->forLandingPage($resource, $landingPage);

    expect($resolved['template']->is($explicit))->toBeTrue()
        ->and($resolved['source'])->toBe(LandingPageTemplateResolverService::SOURCE_EXPLICIT);
});

it('allows the explicit resource system default to override datacenter inheritance', function (): void {
    $inherited = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create(['landing_page_template_id' => $inherited->id]);
    $resource = Resource::factory()->create([
        'resource_type_id' => $this->datasetType->id,
        'datacenter_id' => $datacenter->id,
    ]);

    $resolved = $this->resolver->resolve(
        $resource,
        $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE],
    );

    expect($resolved['template']->is($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]))->toBeTrue()
        ->and($resolved['source'])->toBe(LandingPageTemplateResolverService::SOURCE_EXPLICIT);
});

it('uses the resource system default without a datacenter template', function (): void {
    $datacenter = Datacenter::factory()->create();
    $resource = Resource::factory()->create([
        'resource_type_id' => $this->datasetType->id,
        'datacenter_id' => $datacenter->id,
    ]);

    $resolved = $this->resolver->automatic($resource);

    expect($resolved['template']->is($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]))->toBeTrue()
        ->and($resolved['source'])->toBe(LandingPageTemplateResolverService::SOURCE_DEFAULT);
});

it('ignores datacenter templates for physical object resources', function (): void {
    $resourceTemplate = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create(['landing_page_template_id' => $resourceTemplate->id]);
    $resource = Resource::factory()->create([
        'resource_type_id' => $this->physicalObjectType->id,
        'datacenter_id' => $datacenter->id,
    ]);

    $resolved = $this->resolver->automatic($resource);

    expect($resolved['template']->is($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]))->toBeTrue()
        ->and($resolved['source'])->toBe(LandingPageTemplateResolverService::SOURCE_DEFAULT);
});
