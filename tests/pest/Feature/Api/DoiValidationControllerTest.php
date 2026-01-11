<?php

use App\Models\Resource;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;

beforeEach(function () {
    // Create and authenticate a user for all requests
    // The DOI validation endpoint requires authentication
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('DoiValidationController - Format Validation', function () {
    test('rejects invalid DOI format', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => 'not-a-valid-doi',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'is_valid_format' => false,
                'exists' => false,
            ])
            ->assertJsonStructure(['error']);
    });

    test('accepts valid DOI format 10.XXXX/suffix', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/gfz.test.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    test('accepts DOI with URL prefix', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => 'https://doi.org/10.5880/gfz.test.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    test('accepts DOI with dx.doi.org URL prefix', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => 'https://dx.doi.org/10.5880/gfz.test.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    test('requires DOI parameter', function () {
        $response = $this->postJson('/api/v1/doi/validate', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doi']);
    });
});

describe('DoiValidationController - Duplicate Detection', function () {
    test('detects existing DOI in database', function () {
        // Create a resource with a DOI
        $resource = Resource::factory()->create([
            'doi' => '10.5880/existing.2026.001',
        ]);

        // Create a main title for the resource
        $mainTitleType = TitleType::firstOrCreate(
            ['slug' => 'main-title'],
            ['name' => 'Main Title']
        );
        Title::factory()->create([
            'resource_id' => $resource->id,
            'title_type_id' => $mainTitleType->id,
            'value' => 'Existing Resource Title',
        ]);

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/existing.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => true,
                'existing_resource' => [
                    'id' => $resource->id,
                    'title' => 'Existing Resource Title',
                ],
            ])
            ->assertJsonStructure([
                'last_assigned_doi',
                'suggested_doi',
            ]);
    });

    test('excludes specified resource ID from duplicate check', function () {
        // Create a resource with a DOI
        $resource = Resource::factory()->create([
            'doi' => '10.5880/myresource.2026.001',
        ]);

        // When editing, the resource's own DOI should not be flagged as duplicate
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/myresource.2026.001',
            'exclude_resource_id' => $resource->id,
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    test('still detects duplicate when exclude_resource_id does not match', function () {
        // Create a resource with a DOI
        $resource = Resource::factory()->create([
            'doi' => '10.5880/another.2026.001',
        ]);

        // Trying to use this DOI for a different resource
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/another.2026.001',
            'exclude_resource_id' => 99999, // Different resource ID
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => true,
            ]);
    });
});

describe('DoiValidationController - DOI Suggestions', function () {
    test('suggests next DOI for project.year.number pattern', function () {
        // Create existing DOIs
        Resource::factory()->create(['doi' => '10.5880/fidgeo.2026.001']);
        Resource::factory()->create(['doi' => '10.5880/fidgeo.2026.002']);

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/fidgeo.2026.002',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists' => true,
                'suggested_doi' => '10.5880/fidgeo.2026.003',
            ]);
    });

    test('suggests next DOI for projectdb.number pattern', function () {
        Resource::factory()->create(['doi' => '10.5880/trr228db.100']);

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/trr228db.100',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists' => true,
                'suggested_doi' => '10.5880/trr228db.101',
            ]);
    });

    test('suggests next DOI for gfz.code.year.number pattern', function () {
        Resource::factory()->create(['doi' => '10.5880/gfz.dmjq.2026.005']);

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/gfz.dmjq.2026.005',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists' => true,
                'suggested_doi' => '10.5880/gfz.dmjq.2026.006',
            ]);
    });

    test('suggests next DOI for gfz.section.section.year.number pattern', function () {
        Resource::factory()->create(['doi' => '10.5880/gfz.4.4.2026.003']);

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/gfz.4.4.2026.003',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists' => true,
                'suggested_doi' => '10.5880/gfz.4.4.2026.004',
            ]);
    });

    test('returns last assigned DOI when duplicate detected', function () {
        // Create the "last" resource
        $lastResource = Resource::factory()->create([
            'doi' => '10.5880/latest.2026.999',
            'created_at' => now(),
        ]);

        // Create the duplicate resource (older)
        Resource::factory()->create([
            'doi' => '10.5880/duplicate.2026.001',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/duplicate.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists' => true,
                'last_assigned_doi' => '10.5880/latest.2026.999',
            ]);
    });

    test('skips already existing DOIs when suggesting', function () {
        // Create DOIs 001, 002, 003 - suggestion should be 004
        Resource::factory()->create(['doi' => '10.5880/test.2026.001']);
        Resource::factory()->create(['doi' => '10.5880/test.2026.002']);
        Resource::factory()->create(['doi' => '10.5880/test.2026.003']);

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/test.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists' => true,
                'suggested_doi' => '10.5880/test.2026.004',
            ]);
    });
});

describe('DoiValidationController - Edge Cases', function () {
    test('handles DOI with whitespace', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '  10.5880/gfz.test.2026.001  ',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    test('handles very long DOI suffix', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/very.long.suffix.with.many.parts.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
            ]);
    });

    test('handles DOI with special characters in suffix', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/test-project_v2.2026.001',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
            ]);
    });

    test('validates exclude_resource_id must be positive integer', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/test.2026.001',
            'exclude_resource_id' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['exclude_resource_id']);
    });

    test('validates exclude_resource_id must be integer', function () {
        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/test.2026.001',
            'exclude_resource_id' => 'not-an-integer',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['exclude_resource_id']);
    });
});

describe('DoiValidationController - Authentication', function () {
    test('rejects unauthenticated requests', function () {
        // Create a fresh test case without authentication
        auth()->logout();

        $response = $this->postJson('/api/v1/doi/validate', [
            'doi' => '10.5880/test.2026.001',
        ]);

        $response->assertStatus(401);
    });
});
