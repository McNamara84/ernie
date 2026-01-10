<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Visual Regression Tests
 *
 * Uses assertScreenshotMatches() for screenshot-based regression testing.
 * Baseline screenshots are stored in tests/Browser/Screenshots.
 *
 * When running for the first time, baseline screenshots are generated.
 * On subsequent runs, screenshots are compared against baselines.
 *
 * To update baselines after intentional UI changes:
 * ./vendor/bin/pest tests/pest/Browser/VisualRegressionTest.php --update-snapshots
 *
 * @see https://pestphp.com/docs/browser-testing#visual-regression
 */

describe('Welcome Page Visual Regression', function (): void {
    it('matches welcome page screenshot on desktop', function (): void {
        visit('/')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches welcome page screenshot on mobile', function (): void {
        visit('/')->on()
            ->mobile()
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});

describe('Changelog Visual Regression', function (): void {
    it('matches changelog page screenshot', function (): void {
        visit('/changelog')
            ->wait(1) // Wait for Framer Motion animation
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});

describe('Public Pages Visual Regression', function (): void {
    it('matches about page screenshot', function (): void {
        visit('/about')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches legal notice page screenshot', function (): void {
        visit('/legal-notice')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches login page screenshot', function (): void {
        visit('/login')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});

describe('Landing Page Visual Regression', function (): void {
    beforeEach(function (): void {
        // Ensure test landing page exists
        /** @var TestCase $this */
        $this->seed(\Database\Seeders\PlaywrightTestSeeder::class);
    });

    it('matches published landing page screenshot', function (): void {
        // Use the playwright-published landing page from seeder
        $landingPage = LandingPage::query()
            ->where('slug', 'playwright-published')
            ->first();

        if ($landingPage === null) {
            $this->markTestSkipped('Test landing page not available');
        }

        visit('/10.1234/playwright-published')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});

describe('Authenticated Pages Visual Regression', function (): void {
    it('matches dashboard screenshot', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/dashboard')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches editor page screenshot', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches resources overview screenshot', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches settings page screenshot', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/settings')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});

describe('Admin Pages Visual Regression', function (): void {
    it('matches users management screenshot', function (): void {
        /** @var TestCase $this */
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        $this->actingAs($admin);

        visit('/users')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches logs page screenshot', function (): void {
        /** @var TestCase $this */
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        $this->actingAs($admin);

        visit('/logs')
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});

describe('Dark Mode Visual Regression', function (): void {
    it('matches welcome page in dark mode', function (): void {
        visit('/')
            ->inDarkMode()
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches changelog in dark mode', function (): void {
        visit('/changelog')
            ->inDarkMode()
            ->wait(1)
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });

    it('matches login page in dark mode', function (): void {
        visit('/login')
            ->inDarkMode()
            ->assertNoSmoke()
            ->assertScreenshotMatches();
    });
});
