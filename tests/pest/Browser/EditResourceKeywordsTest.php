<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Pest v4 Browser Tests for editing existing resources with keywords.
 *
 * Regression tests for Issue #600: Editing resources with subjects crashed
 * because filtered keyword collections had non-sequential PHP array keys,
 * causing JSON serialization as objects instead of arrays.
 *
 * @see https://github.com/McNamara84/ernie/issues/600
 * @see https://pestphp.com/docs/browser-testing
 */

describe('Edit Resource with Keywords', function (): void {

    it('loads editor without JS errors for resource with mixed subject types', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $resource = Resource::factory()->create();

        // Create free-text keywords first (filtered out for GCMD, creating key gaps)
        Subject::factory()->count(5)->create([
            'resource_id' => $resource->id,
            'subject_scheme' => null,
        ]);

        // Create GCMD keywords after free-text (will have non-zero keys after filtering)
        Subject::factory()->gcmd()->count(3)->create([
            'resource_id' => $resource->id,
        ]);

        // Create GEMET keywords after GCMD (also non-zero keys after filtering)
        Subject::factory()->count(2)->create([
            'resource_id' => $resource->id,
            'value' => 'Environmental monitoring',
            'subject_scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
            'scheme_uri' => 'https://www.eionet.europa.eu/gemet/',
            'value_uri' => 'https://www.eionet.europa.eu/gemet/concept/2054',
        ]);

        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke();
    });

    it('displays GCMD keywords correctly when editing existing resource', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $resource = Resource::factory()->create();

        // Free-text keywords to create non-sequential keys
        Subject::factory()->count(3)->create([
            'resource_id' => $resource->id,
            'subject_scheme' => null,
        ]);

        Subject::factory()->gcmd()->create([
            'resource_id' => $resource->id,
            'value' => 'EARTHQUAKES',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
        ]);

        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke()
            ->assertSee('EARTHQUAKES');
    });

    it('displays free keywords correctly when editing existing resource', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $resource = Resource::factory()->create();

        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'seismology',
            'subject_scheme' => null,
        ]);

        Subject::factory()->gcmd()->count(4)->create([
            'resource_id' => $resource->id,
        ]);

        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke()
            ->assertSee('seismology');
    });

    it('displays GEMET keywords correctly when editing existing resource', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $resource = Resource::factory()->create();

        // GCMD keywords first to create key gaps for GEMET
        Subject::factory()->gcmd()->count(4)->create([
            'resource_id' => $resource->id,
        ]);

        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Air pollution',
            'subject_scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
            'scheme_uri' => 'https://www.eionet.europa.eu/gemet/',
            'value_uri' => 'https://www.eionet.europa.eu/gemet/concept/197',
        ]);

        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke()
            ->assertSee('Air pollution');
    });
});
