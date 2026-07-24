<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;

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

it('keeps the canonical GFZ datacenter on the resource system default', function (): void {
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id,
    ]);
    $custom = LandingPageTemplate::factory()->create();

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$custom->id}", [
            'datacenter_ids' => [$gfz->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('datacenter_ids');

    expect($gfz->fresh()->landing_page_template_id)
        ->toBe($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id);
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

it('keeps the canonical GFZ datacenter on the IGSN system default', function (): void {
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'igsn_landing_page_template_id' => $this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id,
    ]);
    $custom = LandingPageTemplate::factory()->igsn()->create();

    $this->actingAs($this->admin)
        ->putJson("/landing-pages/{$custom->id}", [
            'datacenter_ids' => [$gfz->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('datacenter_ids');

    expect($gfz->fresh()->igsn_landing_page_template_id)
        ->toBe($this->defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id);
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
