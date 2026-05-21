<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

describe('Sidebar Workspace Screenshots', function (): void {
    beforeEach(function (): void {
        app(Vite::class)
            ->useHotFile(storage_path('framework/testing-vite.hot'))
            ->useBuildDirectory('build');
    });

    it('matches the curation workspace sidebar for admins', function (): void {
        /** @var TestCase $this */
        $admin = User::factory()->create([
            'name' => 'Admin Sidebar Screenshot',
            'email' => 'admin-sidebar-curation@example.test',
            'role' => UserRole::ADMIN,
        ]);

        $this->actingAs($admin);

        visit('/dashboard')
            ->waitForText('Data Editor')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches the administration workspace sidebar for admins', function (): void {
        /** @var TestCase $this */
        $admin = User::factory()->create([
            'name' => 'Admin Sidebar Screenshot',
            'email' => 'admin-sidebar-administration@example.test',
            'role' => UserRole::ADMIN,
        ]);

        $this->actingAs($admin);

        visit('/settings')
            ->waitForText('Old Datasets')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});