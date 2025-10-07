<?php

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('seeds contributor and author roles with metadata', function (): void {
    $this->seed(RoleSeeder::class);

    $rolesBySlug = Role::query()->get()->keyBy('slug');

    expect($rolesBySlug)->toHaveCount(count(RoleSeeder::ROLES));

    foreach (RoleSeeder::ROLES as $expectedRole) {
        $slug = Str::slug($expectedRole['name']);

        expect($rolesBySlug->has($slug))->toBeTrue();

        $role = $rolesBySlug->get($slug);

        expect($role->name)->toBe($expectedRole['name']);
        expect($role->applies_to)->toBe($expectedRole['applies_to']);
        expect($role->is_active_in_ernie)->toBeTrue();
        expect($role->is_active_in_elmo)->toBeTrue();
    }
});

it('makes work package leader roles available to people and institutions', function (): void {
    $this->seed(RoleSeeder::class);

    $role = Role::query()->where('slug', 'work-package-leader')->firstOrFail();

    expect($role->applies_to)->toBe(Role::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION);
    expect(Role::query()->contributorPersons()->pluck('slug'))->toContain('work-package-leader');
    expect(Role::query()->contributorInstitutions()->pluck('slug'))->toContain('work-package-leader');
});
