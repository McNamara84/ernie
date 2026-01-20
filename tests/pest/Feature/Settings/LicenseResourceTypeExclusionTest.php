<?php

declare(strict_types=1);

use App\Models\DateType;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->softwareType = ResourceType::factory()->create(['name' => 'Software', 'slug' => 'software']);
    $this->datasetType = ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset']);
    $this->license = Right::factory()->create([
        'identifier' => 'MIT',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    
    // Create required entities for settings update
    $this->titleType = TitleType::factory()->create(['name' => 'Main', 'slug' => 'main']);
    $this->language = Language::factory()->create(['code' => 'en', 'name' => 'English']);
    $this->dateType = DateType::factory()->create(['name' => 'Created', 'slug' => 'created']);
});

describe('EditorSettingsController loads exclusions', function () {
    it('loads empty excluded_resource_type_ids when no exclusions', function () {
        $this->actingAs($this->admin)
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('licenses', 1)
                ->where('licenses.0.excluded_resource_type_ids', [])
            );
    });

    it('loads excluded resource type ids for licenses', function () {
        $this->license->excludedResourceTypes()->attach($this->softwareType->id);

        $this->actingAs($this->admin)
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('licenses', 1)
                ->where('licenses.0.excluded_resource_type_ids', [$this->softwareType->id])
            );
    });

    it('loads multiple excluded resource type ids', function () {
        $this->license->excludedResourceTypes()->attach([
            $this->softwareType->id,
            $this->datasetType->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('licenses', 1)
                ->where('licenses.0.excluded_resource_type_ids', function ($ids) {
                    $idsArray = is_array($ids) ? $ids : $ids->toArray();

                    return count($idsArray) === 2
                        && in_array($this->softwareType->id, $idsArray)
                        && in_array($this->datasetType->id, $idsArray);
                })
            );
    });
});

describe('EditorSettingsController saves exclusions', function () {
    it('saves excluded resource type ids for licenses', function () {
        $response = $this->actingAs($this->admin)
            ->post('/settings', [
                'resourceTypes' => [
                    ['id' => $this->softwareType->id, 'name' => 'Software', 'active' => true, 'elmo_active' => true],
                    ['id' => $this->datasetType->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
                ],
                'titleTypes' => [
                    ['id' => $this->titleType->id, 'name' => 'Main', 'slug' => 'main', 'active' => true, 'elmo_active' => true],
                ],
                'licenses' => [[
                    'id' => $this->license->id,
                    'active' => true,
                    'elmo_active' => true,
                    'excluded_resource_type_ids' => [$this->softwareType->id],
                ]],
                'languages' => [
                    ['id' => $this->language->id, 'active' => true, 'elmo_active' => true],
                ],
                'dateTypes' => [
                    ['id' => $this->dateType->id, 'active' => true],
                ],
                'maxTitles' => 5,
                'maxLicenses' => 5,
                'thesauri' => [],
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $excludedIds = $this->license->fresh()->excludedResourceTypes()->pluck('resource_types.id')->toArray();
        expect($excludedIds)->toContain($this->softwareType->id);
        expect($excludedIds)->toHaveCount(1);
    });

    it('clears excluded resource type ids when empty array', function () {
        // First add an exclusion
        $this->license->excludedResourceTypes()->attach($this->softwareType->id);

        // Then update with empty array
        $this->actingAs($this->admin)
            ->post('/settings', [
                'resourceTypes' => [
                    ['id' => $this->softwareType->id, 'name' => 'Software', 'active' => true, 'elmo_active' => true],
                    ['id' => $this->datasetType->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
                ],
                'titleTypes' => [
                    ['id' => $this->titleType->id, 'name' => 'Main', 'slug' => 'main', 'active' => true, 'elmo_active' => true],
                ],
                'licenses' => [[
                    'id' => $this->license->id,
                    'active' => true,
                    'elmo_active' => true,
                    'excluded_resource_type_ids' => [],
                ]],
                'languages' => [
                    ['id' => $this->language->id, 'active' => true, 'elmo_active' => true],
                ],
                'dateTypes' => [
                    ['id' => $this->dateType->id, 'active' => true],
                ],
                'maxTitles' => 5,
                'maxLicenses' => 5,
                'thesauri' => [],
            ])
            ->assertRedirect();

        expect($this->license->fresh()->excludedResourceTypes()->count())->toBe(0);
    });

    it('syncs excluded resource types correctly', function () {
        // First add software exclusion
        $this->license->excludedResourceTypes()->attach($this->softwareType->id);

        // Then change to dataset exclusion
        $this->actingAs($this->admin)
            ->post('/settings', [
                'resourceTypes' => [
                    ['id' => $this->softwareType->id, 'name' => 'Software', 'active' => true, 'elmo_active' => true],
                    ['id' => $this->datasetType->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
                ],
                'titleTypes' => [
                    ['id' => $this->titleType->id, 'name' => 'Main', 'slug' => 'main', 'active' => true, 'elmo_active' => true],
                ],
                'licenses' => [[
                    'id' => $this->license->id,
                    'active' => true,
                    'elmo_active' => true,
                    'excluded_resource_type_ids' => [$this->datasetType->id],
                ]],
                'languages' => [
                    ['id' => $this->language->id, 'active' => true, 'elmo_active' => true],
                ],
                'dateTypes' => [
                    ['id' => $this->dateType->id, 'active' => true],
                ],
                'maxTitles' => 5,
                'maxLicenses' => 5,
                'thesauri' => [],
            ])
            ->assertRedirect();

        $excludedIds = $this->license->fresh()->excludedResourceTypes()->pluck('resource_types.id')->toArray();
        expect($excludedIds)->toBe([$this->datasetType->id]);
        expect($excludedIds)->not->toContain($this->softwareType->id);
    });

    it('validates excluded_resource_type_ids must exist', function () {
        $this->actingAs($this->admin)
            ->post('/settings', [
                'resourceTypes' => [
                    ['id' => $this->softwareType->id, 'name' => 'Software', 'active' => true, 'elmo_active' => true],
                ],
                'titleTypes' => [
                    ['id' => $this->titleType->id, 'name' => 'Main', 'slug' => 'main', 'active' => true, 'elmo_active' => true],
                ],
                'licenses' => [[
                    'id' => $this->license->id,
                    'active' => true,
                    'elmo_active' => true,
                    'excluded_resource_type_ids' => [99999], // Non-existent ID
                ]],
                'languages' => [
                    ['id' => $this->language->id, 'active' => true, 'elmo_active' => true],
                ],
                'dateTypes' => [
                    ['id' => $this->dateType->id, 'active' => true],
                ],
                'maxTitles' => 5,
                'maxLicenses' => 5,
                'thesauri' => [],
            ])
            ->assertSessionHasErrors('licenses.0.excluded_resource_type_ids.0');
    });
});
