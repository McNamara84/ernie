<?php

declare(strict_types=1);

use App\Http\Controllers\DatacenterController;
use App\Models\Datacenter;
use App\Models\IgsnMetadata;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\User;
use App\Services\DatacenterNameService;
use Illuminate\Support\Facades\DB;

covers(DatacenterController::class);

uses()->group('datacenters');

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->groupLeader = User::factory()->groupLeader()->create();
    $this->curator = User::factory()->curator()->create();
    $this->beginner = User::factory()->beginner()->create();
});

describe('Datacenter Listing', function () {
    test('authenticated users can list datacenters', function () {
        Datacenter::factory()->withName('GFZ Potsdam')->create();
        Datacenter::factory()->withName('AWI Bremerhaven')->create();

        $response = $this->actingAs($this->beginner)
            ->getJson('/api/datacenters');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['id', 'name']]);
    });

    test('datacenters are ordered alphabetically', function () {
        Datacenter::factory()->withName('ZZZ Last')->create();
        Datacenter::factory()->withName('AAA First')->create();

        $response = $this->actingAs($this->curator)
            ->getJson('/api/datacenters');

        $response->assertOk();
        $datacenters = $response->json();
        expect($datacenters[0]['name'])->toBe('AAA First');
        expect($datacenters[1]['name'])->toBe('ZZZ Last');
    });

    test('unauthenticated users cannot list datacenters', function () {
        $response = $this->getJson('/api/datacenters');

        $response->assertUnauthorized();
    });

    test('empty list returns empty array', function () {
        $response = $this->actingAs($this->beginner)
            ->getJson('/api/datacenters');

        $response->assertOk()
            ->assertJsonCount(0);
    });
});

describe('Datacenter Creation', function () {
    test('admin can create a datacenter', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => 'GFZ Potsdam',
            ]);

        $response->assertCreated()
            ->assertJson([
                'datacenter' => [
                    'name' => 'GFZ Potsdam',
                    'resources_count' => 0,
                ],
                'message' => 'Datacenter created successfully.',
            ]);

        expect(Datacenter::where('name', 'GFZ Potsdam')->exists())->toBeTrue();
        $datacenter = Datacenter::where('name', 'GFZ Potsdam')->firstOrFail();
        $defaults = LandingPageTemplate::ensureSystemTemplatesExist();
        expect($datacenter->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
            ->and($datacenter->igsn_landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id);
    });

    test('group leader can create a datacenter', function () {
        $response = $this->actingAs($this->groupLeader)
            ->postJson('/api/datacenters', [
                'name' => 'AWI Bremerhaven',
            ]);

        $response->assertCreated();
        expect(Datacenter::where('name', 'AWI Bremerhaven')->exists())->toBeTrue();
    });

    test('curator cannot create a datacenter', function () {
        $response = $this->actingAs($this->curator)
            ->postJson('/api/datacenters', [
                'name' => 'Test Datacenter',
            ]);

        $response->assertForbidden();
        expect(Datacenter::count())->toBe(0);
    });

    test('beginner cannot create a datacenter', function () {
        $response = $this->actingAs($this->beginner)
            ->postJson('/api/datacenters', [
                'name' => 'Test Datacenter',
            ]);

        $response->assertForbidden();
        expect(Datacenter::count())->toBe(0);
    });

    test('name is trimmed before validation', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => '  GFZ Potsdam  ',
            ]);

        $response->assertCreated();
        expect(Datacenter::first()->name)->toBe('GFZ Potsdam');
    });

    test('duplicate name returns 422', function () {
        Datacenter::factory()->withName('GFZ Potsdam')->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => 'GFZ Potsdam',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('trimmed duplicate name returns 422', function () {
        Datacenter::factory()->withName('GFZ Potsdam')->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => '  GFZ Potsdam  ',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('empty name is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => '',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('missing name is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('name exceeding 255 characters is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => str_repeat('x', 256),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });
});

describe('Datacenter Renaming', function () {
    test('admin renames an assigned datacenter without moving resources, IGSNs or template assignments', function () {
        $resourceTemplate = LandingPageTemplate::factory()->create();
        $igsnTemplate = LandingPageTemplate::factory()->igsn()->create();
        $datacenter = Datacenter::factory()->withName('Original name')->create([
            'landing_page_template_id' => $resourceTemplate->id,
            'igsn_landing_page_template_id' => $igsnTemplate->id,
        ]);
        $resource = Resource::factory()->create(['datacenter_id' => $datacenter->id]);
        $igsn = Resource::factory()->create(['datacenter_id' => $datacenter->id]);
        IgsnMetadata::create(['resource_id' => $igsn->id]);

        $this->actingAs($this->admin)
            ->patchJson("/api/datacenters/{$datacenter->id}", ['name' => '  New name  '])
            ->assertOk()
            ->assertJsonPath('datacenter.id', $datacenter->id)
            ->assertJsonPath('datacenter.name', 'New name')
            ->assertJsonPath('datacenter.resources_count', 2);

        expect($resource->fresh()->datacenter_id)->toBe($datacenter->id)
            ->and($resource->fresh()->datacenter?->name)->toBe('New name')
            ->and($igsn->fresh()->datacenter_id)->toBe($datacenter->id)
            ->and($igsn->fresh()->datacenter?->name)->toBe('New name')
            ->and($datacenter->fresh()->landing_page_template_id)->toBe($resourceTemplate->id)
            ->and($datacenter->fresh()->igsn_landing_page_template_id)->toBe($igsnTemplate->id)
            ->and(app(DatacenterNameService::class)->find('Original name')?->id)->toBe($datacenter->id);
    });

    test('group leader can rename a datacenter already assigned to a resource', function () {
        $datacenter = Datacenter::factory()->create();
        Resource::factory()->create(['datacenter_id' => $datacenter->id]);

        $this->actingAs($this->groupLeader)
            ->patchJson("/api/datacenters/{$datacenter->id}", ['name' => 'Group leader name'])
            ->assertOk();

        expect($datacenter->fresh()->name)->toBe('Group leader name');
    });

    test('unauthorized roles and guests cannot rename datacenters', function () {
        $datacenter = Datacenter::factory()->withName('Protected name')->create();

        foreach ([$this->curator, $this->beginner] as $user) {
            $this->actingAs($user)
                ->patchJson("/api/datacenters/{$datacenter->id}", ['name' => 'Changed'])
                ->assertForbidden();
        }
        auth()->logout();
        $this->patchJson("/api/datacenters/{$datacenter->id}", ['name' => 'Changed'])->assertUnauthorized();
        expect($datacenter->fresh()->name)->toBe('Protected name');
    });

    test('empty, missing and overlong names are rejected', function () {
        $datacenter = Datacenter::factory()->withName('Original')->create();
        foreach ([[], ['name' => '   '], ['name' => str_repeat('x', 256)]] as $payload) {
            $this->actingAs($this->admin)
                ->patchJson("/api/datacenters/{$datacenter->id}", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }
        expect($datacenter->fresh()->name)->toBe('Original');
    });

    test('a renamed datacenter reserves every earlier name against other datacenters', function () {
        $first = Datacenter::factory()->withName('First name')->create();
        $second = Datacenter::factory()->withName('Second name')->create();
        $this->actingAs($this->admin)
            ->patchJson("/api/datacenters/{$first->id}", ['name' => 'Third name'])
            ->assertOk();
        $this->patchJson("/api/datacenters/{$first->id}", ['name' => 'Fourth name'])->assertOk();

        $this->patchJson("/api/datacenters/{$second->id}", ['name' => ' first NAME '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/datacenters', ['name' => 'third name'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        expect($first->fresh()->name)->toBe('Fourth name')
            ->and($second->fresh()->name)->toBe('Second name')
            ->and(app(DatacenterNameService::class)->find('First name')?->id)->toBe($first->id)
            ->and(app(DatacenterNameService::class)->find('Third name')?->id)->toBe($first->id)
            ->and(Datacenter::query()->count())->toBe(2);
    });

    test('source names resolve to the same datacenter after renaming', function () {
        $datacenter = Datacenter::factory()->withName('Imported name')->create();
        $this->actingAs($this->admin)
            ->patchJson("/api/datacenters/{$datacenter->id}", ['name' => 'Editorial name'])
            ->assertOk();

        $resolved = app(DatacenterNameService::class)->findOrCreate('Imported name');
        expect($resolved->id)->toBe($datacenter->id)
            ->and($resolved->fresh()->name)->toBe('Editorial name')
            ->and(Datacenter::query()->count())->toBe(1)
            ->and(DB::table('datacenter_name_aliases')->where('datacenter_id', $datacenter->id)->count())->toBe(2);
    });

    test('missing datacenters return 404', function () {
        $this->actingAs($this->admin)
            ->patchJson('/api/datacenters/99999', ['name' => 'New name'])
            ->assertNotFound();
    });
});

describe('Datacenter Deletion', function () {
    test('admin can delete an unused datacenter', function () {
        $datacenter = Datacenter::factory()->withName('Unused DC')->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Datacenter deleted successfully.']);

        expect(Datacenter::find($datacenter->id))->toBeNull();
    });

    test('group leader can delete an unused datacenter', function () {
        $datacenter = Datacenter::factory()->create();

        $response = $this->actingAs($this->groupLeader)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertOk();
        expect(Datacenter::find($datacenter->id))->toBeNull();
    });

    test('cannot delete a datacenter with assigned resources', function () {
        $datacenter = Datacenter::factory()->withName('Busy DC')->create();
        $resource = Resource::factory()->create();
        $resource->update(['datacenter_id' => $datacenter->id]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertUnprocessable()
            ->assertJson(['message' => 'Cannot delete datacenter with assigned resources.']);

        expect(Datacenter::find($datacenter->id))->not->toBeNull();
    });

    test('curator cannot delete a datacenter', function () {
        $datacenter = Datacenter::factory()->create();

        $response = $this->actingAs($this->curator)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertForbidden();
        expect(Datacenter::find($datacenter->id))->not->toBeNull();
    });

    test('beginner cannot delete a datacenter', function () {
        $datacenter = Datacenter::factory()->create();

        $response = $this->actingAs($this->beginner)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertForbidden();
        expect(Datacenter::find($datacenter->id))->not->toBeNull();
    });

    test('returns 404 for non-existent datacenter', function () {
        $response = $this->actingAs($this->admin)
            ->deleteJson('/api/datacenters/99999');

        $response->assertNotFound();
    });
});
