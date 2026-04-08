<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

describe('JSON Upload Error Handling', function () {
    test('rejects non-JSON file extension', function () {
        $this->actingAs(User::factory()->create());

        $file = UploadedFile::fake()->createWithContent('data.txt', '{"titles":[{"title":"Test"}]}');

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'filename',
                'error' => ['category', 'code', 'message'],
            ]);
    });

    test('rejects invalid JSON content', function () {
        $this->actingAs(User::factory()->create());

        $file = UploadedFile::fake()->createWithContent('bad.json', '{not valid json}}}');

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error.code', 'json_parse_error');
    });

    test('rejects unrecognized JSON structure', function () {
        $this->actingAs(User::factory()->create());

        $json = json_encode(['random' => 'data', 'no' => 'datacite']);
        $file = UploadedFile::fake()->createWithContent('unknown.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error.code', 'invalid_json_structure');
    });

    test('rejects JSON that does not validate against DataCite schema', function () {
        $this->actingAs(User::factory()->create());

        // Missing required 'creators' field
        $json = json_encode([
            'data' => [
                'attributes' => [
                    'titles' => [['title' => 'Test']],
                    // creators missing → schema violation
                ],
            ],
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid-schema.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'json_schema_validation_error');
    });

    test('returns structured error response with validation errors array', function () {
        $this->actingAs(User::factory()->create());

        $json = json_encode([
            'data' => [
                'attributes' => [
                    'titles' => [['title' => 'Test']],
                ],
            ],
        ]);

        $file = UploadedFile::fake()->createWithContent('schema-errors.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'filename',
                'error' => ['category', 'code', 'message'],
                'errors',
            ]);
    });

    test('rejects request without file', function () {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/dashboard/upload-json', []);

        $response->assertStatus(422)
            ->assertInvalid('file');
    });

    test('rejects unauthenticated request', function () {
        $json = json_encode(['titles' => [['title' => 'Test']]]);
        $file = UploadedFile::fake()->createWithContent('test.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $response->assertUnauthorized();
    });
});
