<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

it('assigns the canonical GFZ datacenter to the resource system template', function (): void {
    $resourceDefault = LandingPageTemplate::ensureDefaultTemplateExists();
    $otherTemplate = LandingPageTemplate::factory()->create();
    $gfz = Datacenter::factory()->create([
        'name' => Datacenter::GFZ_NAME,
        'landing_page_template_id' => $otherTemplate->id,
    ]);

    $migration = require database_path('migrations/2026_07_23_000001_assign_landing_page_templates_to_datacenters.php');
    $migration->up();

    expect($gfz->fresh()->landing_page_template_id)->toBe($resourceDefault->id);
});

it('prefers the oldest specialized datacenter while reducing legacy assignments', function (): void {
    $gfz = Datacenter::factory()->create(['name' => Datacenter::GFZ_NAME]);
    $oldestSpecialized = Datacenter::factory()->create(['name' => 'Specialized A']);
    $newerSpecialized = Datacenter::factory()->create(['name' => 'Specialized B']);
    $resource = Resource::factory()->create(['datacenter_id' => null]);

    Schema::create('resource_datacenter', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('resource_id');
        $table->foreignId('datacenter_id');
        $table->timestamps();
    });

    $now = now();
    DB::table('resource_datacenter')->insert([
        [
            'resource_id' => $resource->id,
            'datacenter_id' => $gfz->id,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'resource_id' => $resource->id,
            'datacenter_id' => $oldestSpecialized->id,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'resource_id' => $resource->id,
            'datacenter_id' => $newerSpecialized->id,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    Log::spy();

    $migration = require database_path('migrations/2026_07_23_000002_convert_resource_datacenters_to_single_assignment.php');
    $migration->up();

    expect($resource->fresh()->datacenter_id)->toBe($oldestSpecialized->id)
        ->and(Schema::hasTable('resource_datacenter'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            'Reduced multiple resource datacenter assignments to one during migration',
            Mockery::on(fn (array $context): bool => $context['resource_id'] === $resource->id
                && $context['selected_datacenter_id'] === $oldestSpecialized->id
                && $context['selection_rule'] === 'oldest-specialized-assignment'),
        );
});
