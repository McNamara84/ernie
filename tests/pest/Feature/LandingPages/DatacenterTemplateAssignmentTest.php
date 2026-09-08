<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\PortalCacheInvalidationService;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->defaults = LandingPageTemplate::ensureSystemTemplatesExist();
});

it('assigns multiple datacenters while cloning a regular template', function (): void {
    $first = Datacenter::factory()->create();
    $second = Datacenter::factory()->create();

    $response = $this->actingAs($this->admin)->postJson('/landing-pages', [
        'name' => 'Datacenter Template',
        'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
        'datacenter_ids' => [$first->id, $second->id],
    ]);

    $response->assertCreated()
        ->assertJsonCount(2, 'template.datacenters');
    $templateId = $response->json('template.id');

    expect($first->fresh()->landing_page_template_id)->toBe($templateId)
        ->and($second->fresh()->landing_page_template_id)->toBe($templateId);
});

it('atomically moves a datacenter from one regular template to another', function (): void {
    $firstTemplate = LandingPageTemplate::factory()->create();
    $secondTemplate = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create(['landing_page_template_id' => $firstTemplate->id]);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$secondTemplate->id}", [
            'datacenter_ids' => [$datacenter->id],
        ])
        ->assertOk();

    expect($datacenter->fresh()->landing_page_template_id)->toBe($secondTemplate->id)
        ->and($firstTemplate->datacenters()->exists())->toBeFalse();
});

it('flushes the portal payload cache when a datacenter template assignment changes', function (): void {
    $template = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create();
    $portalCache = Mockery::mock(PortalCacheInvalidationService::class);
    $portalCache->shouldReceive('schedule')->once()->with(PortalScope::cases(), [PortalCacheArea::PAGE]);
    app()->instance(PortalCacheInvalidationService::class, $portalCache);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$template->id}", ['datacenter_ids' => [$datacenter->id]])
        ->assertOk();
});

it('does not flush the portal payload cache when datacenter assignments are unchanged', function (): void {
    $template = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create(['landing_page_template_id' => $template->id]);
    $portalCache = Mockery::mock(PortalCacheInvalidationService::class);
    $portalCache->shouldNotReceive('schedule');
    app()->instance(PortalCacheInvalidationService::class, $portalCache);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$template->id}", ['datacenter_ids' => [$datacenter->id]])
        ->assertOk();
});

it('assigns datacenters to IGSN templates through the independent IGSN slot', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create();
    $datacenter = Datacenter::factory()->create();

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$template->id}", [
            'datacenter_ids' => [$datacenter->id],
        ])
        ->assertOk()
        ->assertJsonPath('template.datacenters.0.id', $datacenter->id);

    expect($datacenter->fresh()->igsn_landing_page_template_id)->toBe($template->id);
});

it('allows a custom resource template to take the GFZ assignment without changing its IGSN template', function (): void {
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id,
        'igsn_landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id,
    ]);
    $custom = LandingPageTemplate::factory()->create();

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$custom->id}", [
            'datacenter_ids' => [$gfz->id],
        ])
        ->assertOk()
        ->assertJsonPath('template.datacenters.0.id', $gfz->id);

    $gfz->refresh();
    expect($gfz->landing_page_template_id)->toBe($custom->id)
        ->and($gfz->igsn_landing_page_template_id)
        ->toBe($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id);
});

it('allows a group leader to assign GFZ and other datacenters while cloning a resource template', function (): void {
    $groupLeader = User::factory()->groupLeader()->create();
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id,
    ]);
    $other = Datacenter::factory()->create();

    $response = $this->actingAs($groupLeader)
        ->postJson('/landing-pages', [
            'name' => 'Custom GFZ Resource Template',
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
            'datacenter_ids' => [$gfz->id, $other->id],
        ]);

    $response->assertCreated()
        ->assertJsonCount(2, 'template.datacenters');
    $templateId = $response->json('template.id');

    expect($gfz->fresh()->landing_page_template_id)->toBe($templateId)
        ->and($other->fresh()->landing_page_template_id)->toBe($templateId);
});

it('keeps resource and IGSN template assignments independent on the same datacenter', function (): void {
    $resourceTemplate = LandingPageTemplate::factory()->create();
    $igsnTemplate = LandingPageTemplate::factory()->igsn()->create();
    $datacenter = Datacenter::factory()->create([
        'landing_page_template_id' => $resourceTemplate->id,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$igsnTemplate->id}", [
            'datacenter_ids' => [$datacenter->id],
        ])
        ->assertOk();

    $datacenter->refresh();
    expect($datacenter->landing_page_template_id)->toBe($resourceTemplate->id)
        ->and($datacenter->igsn_landing_page_template_id)->toBe($igsnTemplate->id);
});

it('allows a custom IGSN template to take the canonical GFZ assignment without changing its resource template', function (): void {
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id,
        'igsn_landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id,
    ]);
    $custom = LandingPageTemplate::factory()->igsn()->create();

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$custom->id}", [
            'datacenter_ids' => [$gfz->id],
        ])
        ->assertOk()
        ->assertJsonPath('template.datacenters.0.id', $gfz->id);

    $gfz->refresh();
    expect($gfz->igsn_landing_page_template_id)->toBe($custom->id)
        ->and($gfz->landing_page_template_id)
        ->toBe($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id);
});

it('allows the IGSN copy template to keep an existing datacenter assignment', function (): void {
    $igsnCopyTemplate = $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN];
    $datacenter = Datacenter::factory()->create([
        'igsn_landing_page_template_id' => $igsnCopyTemplate->id,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$igsnCopyTemplate->id}", [
            'datacenter_ids' => [$datacenter->id],
        ])
        ->assertOk();

    expect($datacenter->fresh()->igsn_landing_page_template_id)->toBe($igsnCopyTemplate->id);
});

it('preserves existing assignments when a copy template is updated with an empty selection', function (string $type): void {
    $foreignKey = LandingPageTemplate::datacenterAssignmentColumnForType($type);
    $copyTemplate = $this->defaults[$type];
    $datacenter = Datacenter::factory()->create([
        $foreignKey => $copyTemplate->id,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$copyTemplate->id}", [
            'datacenter_ids' => [],
        ])
        ->assertOk();

    expect($datacenter->fresh()->getAttribute($foreignKey))->toBe($copyTemplate->id);
})->with([
    'resource' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
    'IGSN' => LandingPageTemplate::TEMPLATE_TYPE_IGSN,
]);

it('returns datacenters removed from a custom template to the matching copy template', function (string $type): void {
    $foreignKey = LandingPageTemplate::datacenterAssignmentColumnForType($type);
    $factory = LandingPageTemplate::factory();
    if ($type === LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
        $factory = $factory->igsn();
    }
    $custom = $factory->create();
    $datacenter = Datacenter::factory()->create([$foreignKey => $custom->id]);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$custom->id}", [
            'datacenter_ids' => [],
        ])
        ->assertOk()
        ->assertJsonCount(0, 'template.datacenters');

    expect($datacenter->fresh()->getAttribute($foreignKey))->toBe($this->defaults[$type]->id)
        ->and($custom->fresh()?->getDatacenterUsageCount())->toBe(0);
})->with([
    'resource' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
    'IGSN' => LandingPageTemplate::TEMPLATE_TYPE_IGSN,
]);

it('allows a copy template to reclaim a datacenter from a custom template', function (string $type): void {
    $foreignKey = LandingPageTemplate::datacenterAssignmentColumnForType($type);
    $factory = LandingPageTemplate::factory();
    if ($type === LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
        $factory = $factory->igsn();
    }
    $custom = $factory->create();
    $datacenter = Datacenter::factory()->create([$foreignKey => $custom->id]);
    $copyTemplate = $this->defaults[$type];

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$copyTemplate->id}", [
            'datacenter_ids' => [$datacenter->id],
        ])
        ->assertOk();

    expect($datacenter->fresh()->getAttribute($foreignKey))->toBe($copyTemplate->id)
        ->and($custom->fresh()?->getDatacenterUsageCount())->toBe(0);
})->with([
    'resource' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
    'IGSN' => LandingPageTemplate::TEMPLATE_TYPE_IGSN,
]);

it('preserves the existing GFZ assignment when the IGSN copy template is updated with an empty selection', function (): void {
    $igsnCopyTemplate = $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN];
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'igsn_landing_page_template_id' => $igsnCopyTemplate->id,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$igsnCopyTemplate->id}", [
            'datacenter_ids' => [],
        ])
        ->assertOk();

    expect($gfz->fresh()->igsn_landing_page_template_id)->toBe($igsnCopyTemplate->id);
});

it('allows a group leader to assign GFZ and other datacenters while cloning an IGSN template', function (): void {
    $groupLeader = User::factory()->groupLeader()->create();
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id,
        'igsn_landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id,
    ]);
    $other = Datacenter::factory()->create();

    $response = $this->actingAs($groupLeader)->postJson('/landing-pages', [
        'name' => 'GFZ Shared IGSN Template',
        'template_type' => LandingPageTemplate::TEMPLATE_TYPE_IGSN,
        'datacenter_ids' => [$gfz->id, $other->id],
    ]);

    $response->assertCreated()
        ->assertJsonCount(2, 'template.datacenters');
    $templateId = $response->json('template.id');

    expect($gfz->fresh()->igsn_landing_page_template_id)->toBe($templateId)
        ->and($other->fresh()->igsn_landing_page_template_id)->toBe($templateId)
        ->and($gfz->fresh()->landing_page_template_id)
        ->toBe($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id);
});

it('lets the IGSN copy template reclaim GFZ after a custom assignment', function (): void {
    $custom = LandingPageTemplate::factory()->igsn()->create();
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'igsn_landing_page_template_id' => $custom->id,
    ]);
    $igsnCopyTemplate = $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN];

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$igsnCopyTemplate->id}", [
            'datacenter_ids' => [$gfz->id],
        ])
        ->assertOk();

    expect($gfz->fresh()->igsn_landing_page_template_id)->toBe($igsnCopyTemplate->id)
        ->and($custom->fresh()?->igsnDatacenters()->exists())->toBeFalse();
});

it('inherits an IGSN template for a physical object resource', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create();
    $datacenter = Datacenter::factory()->create([
        'igsn_landing_page_template_id' => $template->id,
    ]);
    $resourceType = ResourceType::query()->firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true, 'is_elmo_active' => true],
    );
    $resource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'datacenter_id' => $datacenter->id,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/resources/{$resource->id}/landing-page/template-options")
        ->assertOk()
        ->assertJsonPath('datacenter.id', $datacenter->id)
        ->assertJsonPath('datacenter_template.id', $template->id)
        ->assertJsonPath('automatic_template.id', $template->id)
        ->assertJsonPath('automatic_source', 'datacenter')
        ->assertJsonPath('supports_datacenter_inheritance', true);
});

it('blocks deleting an IGSN template assigned to a datacenter', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create();
    Datacenter::factory()->create(['igsn_landing_page_template_id' => $template->id]);

    $this->actingAs($this->admin)
        ->deleteJson("/landing-pages/{$template->id}")
        ->assertUnprocessable()
        ->assertJsonPath('error', 'template_assigned_to_datacenters');
});

it('blocks deleting a template assigned to a datacenter', function (): void {
    $template = LandingPageTemplate::factory()->create();
    Datacenter::factory()->create(['landing_page_template_id' => $template->id]);

    $this->actingAs($this->admin)
        ->deleteJson("/landing-pages/{$template->id}")
        ->assertUnprocessable()
        ->assertJsonPath('error', 'template_assigned_to_datacenters');
});

it('returns the automatic template context for a resource', function (): void {
    $template = LandingPageTemplate::factory()->create();
    $datacenter = Datacenter::factory()->create(['landing_page_template_id' => $template->id]);
    $resourceType = ResourceType::query()->firstOrCreate(
        ['slug' => 'dataset'],
        ['name' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true],
    );
    $resource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'datacenter_id' => $datacenter->id,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/resources/{$resource->id}/landing-page/template-options")
        ->assertOk()
        ->assertJsonPath('datacenter.id', $datacenter->id)
        ->assertJsonPath('automatic_template.id', $template->id)
        ->assertJsonPath('automatic_source', 'datacenter')
        ->assertJsonPath('supports_datacenter_inheritance', true);
});
