<?php

declare(strict_types=1);

use App\Http\Controllers\BatchIgsnController;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;

covers(BatchIgsnController::class);

beforeEach(function (): void {
    // Create PhysicalObject resource type needed for IGSNs
    $resourceType = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );

    $this->resourceType = $resourceType;
});

describe('DELETE /igsns/batch', function (): void {
    test('admin can batch delete IGSNs', function (): void {
        $admin = User::factory()->admin()->create();

        $resource1 = Resource::factory()->create(['resource_type_id' => $this->resourceType->id]);
        IgsnMetadata::create(['resource_id' => $resource1->id, 'status' => 'uploaded']);

        $resource2 = Resource::factory()->create(['resource_type_id' => $this->resourceType->id]);
        IgsnMetadata::create(['resource_id' => $resource2->id, 'status' => 'uploaded']);

        $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => [$resource1->id, $resource2->id]])
            ->assertRedirect(route('igsns.index'));

        $this->assertDatabaseMissing('resources', ['id' => $resource1->id]);
        $this->assertDatabaseMissing('resources', ['id' => $resource2->id]);
    });

    test('non-admin cannot batch delete IGSNs', function (): void {
        $curator = User::factory()->curator()->create();

        $resource = Resource::factory()->create(['resource_type_id' => $this->resourceType->id]);
        IgsnMetadata::create(['resource_id' => $resource->id, 'status' => 'uploaded']);

        $this->actingAs($curator)
            ->delete('/igsns/batch', ['ids' => [$resource->id]])
            ->assertForbidden();
    });

    test('validates that ids are required', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => []])
            ->assertSessionHasErrors('ids');
    });

    test('validates that ids exist in database', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => [99999]])
            ->assertSessionHasErrors('ids.0');
    });

    test('rejects non-IGSN resources', function (): void {
        $admin = User::factory()->admin()->create();
        $resource = Resource::factory()->create(); // No IGSN metadata

        $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => [$resource->id]])
            ->assertSessionHasErrors('ids');
    });
});
