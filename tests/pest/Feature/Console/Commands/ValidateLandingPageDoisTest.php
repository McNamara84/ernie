<?php

declare(strict_types=1);

use App\Console\Commands\ValidateLandingPageDois;
use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Console\Command;

covers(ValidateLandingPageDois::class);

// =========================================================================
// Validation with valid DOIs
// =========================================================================

describe('all DOIs valid', function () {
    it('reports success when all DOIs are valid', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/test.2025.001']);
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/test.2025.001',
        ]);

        $this->artisan('landing-pages:validate-dois')
            ->assertExitCode(Command::SUCCESS)
            ->expectsOutputToContain('All landing page DOIs are valid');
    });

    it('ignores landing pages without doi_prefix', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => null,
        ]);

        $this->artisan('landing-pages:validate-dois')
            ->assertExitCode(Command::SUCCESS);
    });
});

// =========================================================================
// Detecting invalid DOIs
// =========================================================================

describe('invalid DOI detection', function () {
    it('detects invalid DOI format', function () {
        $resource = Resource::factory()->create(['doi' => 'INVALID']);
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => 'INVALID',
        ]);

        $this->artisan('landing-pages:validate-dois')
            ->assertExitCode(Command::FAILURE);
    });

    it('detects missing 10. prefix', function () {
        $resource = Resource::factory()->create(['doi' => '5880/test']);
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '5880/test',
        ]);

        $this->artisan('landing-pages:validate-dois')
            ->assertExitCode(Command::FAILURE);
    });
});

// =========================================================================
// --fix option
// =========================================================================

describe('--fix option', function () {
    it('fixes missing 10. prefix', function () {
        $resource = Resource::factory()->create(['doi' => '5880/test.2025']);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '5880/test.2025',
        ]);

        $this->artisan('landing-pages:validate-dois --fix')
            ->assertExitCode(Command::FAILURE); // still exits with failure because it found issues

        $landingPage->refresh();
        expect($landingPage->doi_prefix)->toBe('10.5880/test.2025');
    });

    it('fixes wrong separator', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880-GFZ.2025']);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880-GFZ.2025',
        ]);

        $this->artisan('landing-pages:validate-dois --fix')
            ->assertExitCode(Command::FAILURE);

        $landingPage->refresh();
        expect($landingPage->doi_prefix)->toBe('10.5880/GFZ.2025');
    });

    it('fixes URL format DOI', function () {
        $resource = Resource::factory()->create(['doi' => 'https://doi.org/10.5880/test']);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => 'https://doi.org/10.5880/test',
        ]);

        $this->artisan('landing-pages:validate-dois --fix')
            ->assertExitCode(Command::FAILURE);

        $landingPage->refresh();
        expect($landingPage->doi_prefix)->toBe('10.5880/test');
    });
});

// =========================================================================
// --dry-run option
// =========================================================================

describe('--dry-run option', function () {
    it('does not apply fixes in dry-run mode', function () {
        $resource = Resource::factory()->create(['doi' => '5880/test']);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '5880/test',
        ]);

        $this->artisan('landing-pages:validate-dois --fix --dry-run')
            ->assertExitCode(Command::FAILURE)
            ->expectsOutputToContain('Dry run');

        $landingPage->refresh();
        expect($landingPage->doi_prefix)->toBe('5880/test');
    });
});

// =========================================================================
// --strict option (for CI)
// =========================================================================

describe('--strict option', function () {
    it('exits with failure code when invalid DOIs exist', function () {
        $resource = Resource::factory()->create(['doi' => 'BAD-DOI']);
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => 'BAD-DOI',
        ]);

        $this->artisan('landing-pages:validate-dois --strict')
            ->assertExitCode(Command::FAILURE);
    });

    it('exits with success when all DOIs are valid', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/valid.doi']);
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/valid.doi',
        ]);

        $this->artisan('landing-pages:validate-dois --strict')
            ->assertExitCode(Command::SUCCESS);
    });
});
