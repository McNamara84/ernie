<?php

declare(strict_types=1);

use App\Enums\EditorContext;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;

it('round trips all three editor selections and license exclusion lists through settings', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $type = ResourceType::factory()->create();
    TitleType::factory()->create();
    DateType::factory()->create();
    Language::factory()->create();
    $right = Right::factory()->create();
    DescriptionType::create(['name' => 'Methods', 'slug' => 'Methods']);
    DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract']);
    $this->seed([
        ContributorTypeSeeder::class,
        RelationTypeSeeder::class,
        IdentifierTypeSeeder::class,
    ]);
    $props = $this->get('/settings')->assertOk()->viewData('page')['props'];
    $groups = ['resourceTypes', 'titleTypes', 'licenses', 'languages', 'dateTypes', 'descriptionTypes', 'contributorPersonRoles', 'contributorInstitutionRoles', 'contributorBothRoles', 'relationTypes', 'identifierTypes'];
    $payload = [];
    foreach ($groups as $group) {
        $payload[$group] = array_map(function (array $row): array {
            $row['active'] = false;
            $row['elmo_active'] = false;
            $row['elmo_msl_active'] = true;

            return $row;
        }, $props[$group]);
    }
    foreach (['thesauri', 'pidSettings'] as $group) {
        $payload[$group] = array_map(fn (array $row): array => [...$row, 'isActive' => false, 'isElmoActive' => false, 'isElmoMslActive' => true], $props[$group]);
    }
    foreach ($payload['licenses'] as &$license) {
        $license['excluded_resource_type_ids'] = [$type->id];
        $license['elmo_excluded_resource_type_ids'] = [];
        $license['elmo_msl_excluded_resource_type_ids'] = [$type->id];
    }
    unset($license);
    $this->post('/settings', $payload)->assertSessionHasNoErrors()->assertRedirect();
    $fresh = $this->get('/settings')->assertOk()->viewData('page')['props'];
    foreach ($groups as $group) {
        foreach ($fresh[$group] as $row) {
            $abstract = $group === 'descriptionTypes' && $row['slug'] === 'Abstract';
            expect($row['active'])->toBe($abstract)->and($row['elmo_active'])->toBe($abstract)->and($row['elmo_msl_active'])->toBeTrue();
        }
    }
    foreach (['thesauri', 'pidSettings'] as $group) {
        foreach ($fresh[$group] as $row) {
            expect($row['isActive'])->toBeFalse()->and($row['isElmoActive'])->toBeFalse()->and($row['isElmoMslActive'])->toBeTrue();
        }
    }
    expect($right->excludedResourceTypes(EditorContext::ERNIE)->count())->toBe(1)
        ->and($right->excludedResourceTypes(EditorContext::ELMO)->count())->toBe(0)
        ->and($right->excludedResourceTypes(EditorContext::ELMO_MSL)->count())->toBe(1);

    $payload['licenses'][0]['elmo_msl_excluded_resource_type_ids'] = [];
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    expect($right->excludedResourceTypes(EditorContext::ERNIE)->count())->toBe(1)
        ->and($right->excludedResourceTypes(EditorContext::ELMO_MSL)->count())->toBe(0);
});

it('validates new flags and exclusions without partially saving other settings', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $type = ResourceType::factory()->create(['is_active' => true]);
    $right = Right::factory()->create();
    $payload = [
        'resourceTypes' => [['id' => $type->id, 'name' => $type->name, 'active' => false, 'elmo_active' => false, 'elmo_msl_active' => 'invalid']],
        'titleTypes' => [], 'languages' => [], 'dateTypes' => [], 'descriptionTypes' => [],
        'licenses' => [['id' => $right->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => [], 'elmo_msl_excluded_resource_type_ids' => [999999]]],
    ];
    $this->post('/settings', $payload)->assertSessionHasErrors(['resourceTypes.0.elmo_msl_active', 'licenses.0.elmo_msl_excluded_resource_type_ids.0']);
    expect($type->fresh()->is_active)->toBeTrue();
});

it('retains new editor values and exclusion lists when an older settings payload omits them', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $type = ResourceType::factory()->create(['is_elmo_msl_active' => true]);
    $right = Right::factory()->create(['is_elmo_msl_active' => false]);
    $right->excludedResourceTypes(EditorContext::ELMO)->attach($type->id);
    $right->excludedResourceTypes(EditorContext::ELMO_MSL)->attach($type->id);
    TitleType::factory()->create();
    DateType::factory()->create();
    Language::factory()->create();
    DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract']);
    $props = $this->get('/settings')->assertOk()->viewData('page')['props'];
    $catalogs = array_intersect_key($props, array_flip(['resourceTypes', 'titleTypes', 'languages', 'dateTypes', 'descriptionTypes']));

    $this->post('/settings', [
        ...$catalogs,
        'resourceTypes' => [['id' => $type->id, 'name' => $type->name, 'active' => false, 'elmo_active' => false]],
        'licenses' => [['id' => $right->id, 'active' => false, 'elmo_active' => false, 'excluded_resource_type_ids' => []]],
    ])->assertSessionHasNoErrors();
    expect($type->fresh()->is_elmo_msl_active)->toBeTrue()->and($right->fresh()->is_elmo_msl_active)->toBeFalse()
        ->and($right->excludedResourceTypes(EditorContext::ELMO)->count())->toBe(1)
        ->and($right->excludedResourceTypes(EditorContext::ELMO_MSL)->count())->toBe(1);
});

it('allows the same resource type to be excluded from multiple licenses independently', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $type = ResourceType::factory()->create();
    $rights = Right::factory()->count(2)->create();

    TitleType::factory()->create();
    DateType::factory()->create();
    Language::factory()->create();
    DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract']);
    $props = $this->get('/settings')->assertOk()->viewData('page')['props'];
    $catalogs = array_intersect_key($props, array_flip(['resourceTypes', 'titleTypes', 'languages', 'dateTypes', 'descriptionTypes']));
    $licenses = $rights->map(fn (Right $right): array => [
        'id' => $right->id, 'active' => true, 'elmo_active' => true, 'elmo_msl_active' => true,
        'excluded_resource_type_ids' => [$type->id],
        'elmo_excluded_resource_type_ids' => [$type->id],
        'elmo_msl_excluded_resource_type_ids' => [$type->id],
    ])->all();
    $this->post('/settings', [
        ...$catalogs, 'licenses' => $licenses,
    ])->assertSessionHasNoErrors();
    foreach ($rights as $right) {
        foreach (EditorContext::cases() as $editor) {
            expect($right->excludedResourceTypes($editor)->count())->toBe(1);
        }
    }
});
