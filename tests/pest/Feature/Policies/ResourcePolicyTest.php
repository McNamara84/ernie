<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Policies\ResourcePolicy;

describe('ResourcePolicy', function () {
    beforeEach(function () {
        $this->policy = new ResourcePolicy();
        $this->resource = Resource::factory()->create();
    });

    describe('viewAny', function () {
        it('allows admin to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });

        it('allows group leader to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });

        it('allows curator to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });

        it('allows beginner to view any resources', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->viewAny($user))->toBeTrue();
        });
    });

    describe('view', function () {
        it('allows admin to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });

        it('allows group leader to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });

        it('allows curator to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });

        it('allows beginner to view a resource', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->view($user, $this->resource))->toBeTrue();
        });
    });

    describe('create', function () {
        it('allows admin to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->create($user))->toBeTrue();
        });

        it('allows group leader to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->create($user))->toBeTrue();
        });

        it('allows curator to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->create($user))->toBeTrue();
        });

        it('allows beginner to create resources', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->create($user))->toBeTrue();
        });
    });

    describe('update', function () {
        it('allows admin to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });

        it('allows group leader to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });

        it('allows curator to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });

        it('allows beginner to update a resource', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->update($user, $this->resource))->toBeTrue();
        });
    });

    describe('delete', function () {
        it('allows admin to delete a resource', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->delete($user, $this->resource))->toBeTrue();
        });

        it('allows group leader to delete a resource', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->delete($user, $this->resource))->toBeTrue();
        });

        it('denies curator from deleting a resource', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->delete($user, $this->resource))->toBeFalse();
        });

        it('denies beginner from deleting a resource', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->delete($user, $this->resource))->toBeFalse();
        });
    });

    describe('importFromDataCite', function () {
        it('allows admin to import from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::ADMIN]);
            expect($this->policy->importFromDataCite($user))->toBeTrue();
        });

        it('allows group leader to import from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
            expect($this->policy->importFromDataCite($user))->toBeTrue();
        });

        it('denies curator from importing from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::CURATOR]);
            expect($this->policy->importFromDataCite($user))->toBeFalse();
        });

        it('denies beginner from importing from DataCite', function () {
            $user = User::factory()->create(['role' => UserRole::BEGINNER]);
            expect($this->policy->importFromDataCite($user))->toBeFalse();
        });
    });
});
