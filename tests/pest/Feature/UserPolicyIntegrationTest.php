<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
});

describe('user management routes authorization', function () {
    test('admin can access user list', function () {
        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertOk();
    });

    test('group leader can access user list', function () {
        $this->actingAs($this->groupLeader)
            ->get(route('users.index'))
            ->assertOk();
    });

    test('curator cannot access user list', function () {
        $this->actingAs($this->curator)
            ->get(route('users.index'))
            ->assertForbidden();
    });

    test('beginner cannot access user list', function () {
        $this->actingAs($this->beginner)
            ->get(route('users.index'))
            ->assertForbidden();
    });
});

describe('resource routes authorization', function () {
    test('authenticated user can access resources', function () {
        $this->actingAs($this->beginner)
            ->get(route('resources'))
            ->assertOk();
    });

    test('unauthenticated user is redirected from resources', function () {
        $this->get(route('resources'))
            ->assertRedirect(route('login'));
    });

    test('authenticated user can access editor', function () {
        $this->actingAs($this->beginner)
            ->get(route('editor'))
            ->assertOk();
    });

    test('unauthenticated user is redirected from editor', function () {
        $this->get(route('editor'))
            ->assertRedirect(route('login'));
    });
});

describe('dashboard authorization', function () {
    test('authenticated user can access dashboard', function () {
        $this->actingAs($this->beginner)
            ->get(route('dashboard'))
            ->assertOk();
    });

    test('unauthenticated user is redirected from dashboard', function () {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    });
});

describe('IGSN routes authorization', function () {
    test('authenticated user can access igsns index', function () {
        $this->actingAs($this->beginner)
            ->get(route('igsns.index'))
            ->assertOk();
    });

    test('unauthenticated user is redirected from igsns', function () {
        $this->get(route('igsns.index'))
            ->assertRedirect(route('login'));
    });
});

describe('delete all resources authorization', function () {
    test('admin can delete all resources', function () {
        $this->actingAs($this->admin)
            ->delete(route('resources.destroy-all'))
            ->assertRedirect();
    });

    test('beginner cannot delete all resources', function () {
        $this->actingAs($this->beginner)
            ->delete(route('resources.destroy-all'))
            ->assertForbidden();
    });
});
