<?php

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'FunderIdentifierTypeSeeder']);

    $this->user = User::factory()->admin()->create();
});

describe('IGSN CSV Upload Error Handling', function () {
    test('returns structured error for missing file', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ]);
    });

    test('returns structured error for invalid file type', function () {
        $txtFile = UploadedFile::fake()->createWithContent('test.pdf', 'PDF content');

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $txtFile]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    });

    test('returns structured error for empty CSV', function () {
        $emptyCsv = UploadedFile::fake()->createWithContent('empty.csv', '');

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $emptyCsv]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure([
                'success',
                'message',
                'filename',
                'error' => [
                    'category',
                    'code',
                    'message',
                ],
            ]);

        expect($response->json('error.code'))->toBe('no_valid_rows');
    });

    test('returns structured error for duplicate IGSN', function () {
        // Create existing resource with IGSN
        $resourceType = ResourceType::where('name', 'PhysicalObject')->first();
        Resource::factory()->create([
            'doi' => 'EXISTING_IGSN_001',
            'resource_type_id' => $resourceType?->id,
        ]);

        // Try to upload CSV with same IGSN
        $csvContent = "igsn|title|name\nEXISTING_IGSN_001|Test Title|Test Author";
        $file = UploadedFile::fake()->createWithContent('duplicate.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure([
                'success',
                'message',
                'filename',
                'errors',
            ]);

        expect($response->json('message'))->toContain('Duplicate');

        // Check errors array has structured format
        $errors = $response->json('errors');
        expect($errors)->toBeArray();
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toHaveKeys(['category', 'code', 'message']);
        expect($errors[0]['code'])->toBe('duplicate_igsn');
    });

    test('returns structured error for missing required fields', function () {
        // CSV with missing title
        $csvContent = "igsn|title|name\nTEST_IGSN_001||Test Author";
        $file = UploadedFile::fake()->createWithContent('missing_title.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure([
                'success',
                'message',
                'filename',
                'errors',
            ]);

        $errors = $response->json('errors');
        expect($errors)->toBeArray();
        expect($errors[0]['code'])->toBe('missing_required_field');
        expect($errors[0]['message'])->toContain('Title');
    });

    test('includes filename in error response', function () {
        $csvContent = "igsn|title|name\n||";
        $file = UploadedFile::fake()->createWithContent('my-samples.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(422);
        expect($response->json('filename'))->toBe('my-samples.csv');
    });

    test('includes row number and identifier in error response', function () {
        // Create existing resource
        $resourceType = ResourceType::where('name', 'PhysicalObject')->first();
        Resource::factory()->create([
            'doi' => 'DUPLICATE_001',
            'resource_type_id' => $resourceType?->id,
        ]);

        $csvContent = "igsn|title|name\nDUPLICATE_001|Test Title|Test Author";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(422);

        $errors = $response->json('errors');
        expect($errors[0]['row'])->toBe(2); // Row 2 (after header)
        expect($errors[0]['identifier'])->toBe('DUPLICATE_001');
    });

    test('returns multiple structured errors for multiple issues', function () {
        // Create existing resources
        $resourceType = ResourceType::where('name', 'PhysicalObject')->first();
        Resource::factory()->create([
            'doi' => 'DUP_001',
            'resource_type_id' => $resourceType?->id,
        ]);
        Resource::factory()->create([
            'doi' => 'DUP_002',
            'resource_type_id' => $resourceType?->id,
        ]);

        $csvContent = "igsn|title|name\nDUP_001|Title 1|Author 1\nDUP_002|Title 2|Author 2";
        $file = UploadedFile::fake()->createWithContent('multiple_errors.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(422);

        $errors = $response->json('errors');
        expect($errors)->toHaveCount(2);
        expect($errors[0]['identifier'])->toBe('DUP_001');
        expect($errors[1]['identifier'])->toBe('DUP_002');
    });
});

describe('IGSN CSV Upload Success Response', function () {
    test('includes filename in success response', function () {
        $csvContent = "igsn|title|name\nNEW_IGSN_001|Test Title|Test Author";
        $file = UploadedFile::fake()->createWithContent('success-upload.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        expect($response->json('filename'))->toBe('success-upload.csv');
        expect($response->json('created'))->toBe(1);
    });
});
