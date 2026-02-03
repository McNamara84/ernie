<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

describe('XML Upload Error Handling', function () {
    test('returns structured error for invalid XML content', function () {
        $this->actingAs(User::factory()->create());

        $invalidXml = UploadedFile::fake()->createWithContent('invalid.xml', 'not valid xml < ');

        $response = $this->postJson('/dashboard/upload-xml', ['file' => $invalidXml]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
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

        expect($response->json('error.category'))->toBe('data');
        expect($response->json('error.code'))->toBe('xml_parse_error');
        expect($response->json('filename'))->toBe('invalid.xml');
    });

    test('returns structured error for file validation failure - wrong type', function () {
        $this->actingAs(User::factory()->create());

        $txtFile = UploadedFile::fake()->createWithContent('test.txt', 'plain text content');

        $response = $this->postJson('/dashboard/upload-xml', ['file' => $txtFile]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
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

        expect($response->json('error.category'))->toBe('validation');
        expect($response->json('error.code'))->toBe('invalid_file_type');
    });

    test('returns structured error for missing file', function () {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/dashboard/upload-xml', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'error' => [
                    'category',
                    'code',
                    'message',
                ],
            ]);

        expect($response->json('error.category'))->toBe('validation');
        expect($response->json('error.code'))->toBe('file_required');
    });

    test('returns structured error for file too large', function () {
        $this->actingAs(User::factory()->create());

        // Create a file larger than 4MB limit
        $largeContent = str_repeat('<?xml version="1.0"?><root>test</root>', 200000);
        $largeFile = UploadedFile::fake()->createWithContent('large.xml', $largeContent);

        $response = $this->postJson('/dashboard/upload-xml', ['file' => $largeFile]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'error' => [
                    'category',
                    'code',
                    'message',
                ],
            ]);

        expect($response->json('error.category'))->toBe('validation');
        expect($response->json('error.code'))->toBe('file_too_large');
    });

    test('includes filename in error response', function () {
        $this->actingAs(User::factory()->create());

        $invalidXml = UploadedFile::fake()->createWithContent('my-dataset.xml', '< invalid >');

        $response = $this->postJson('/dashboard/upload-xml', ['file' => $invalidXml]);

        $response->assertStatus(422);
        expect($response->json('filename'))->toBe('my-dataset.xml');
    });
});
