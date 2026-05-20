<?php

declare(strict_types=1);

use App\Http\Controllers\ApiDocController;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

covers(ApiDocController::class);

describe('JSON response', function () {
    it('returns OpenAPI spec as JSON when JSON is requested', function () {
        $response = getJson('/api/v1/doc');

        $response->assertOk()
            ->assertJsonStructure(['openapi', 'info' => ['title', 'summary'], 'paths', 'servers'])
            ->assertJsonPath('openapi', '3.2.0')
            ->assertJsonPath('info.summary', 'Read-only metadata, vocabulary, and citation endpoints for ERNIE integrations.');
    });

    it('contains app URL in server configuration', function () {
        $response = getJson('/api/v1/doc');

        $response->assertOk();
        $data = $response->json();

        expect($data['servers'][0]['url'])->toBe(config('app.url'));
        expect($data['servers'][0]['name'])->toBe('Current ERNIE deployment');
    });

    it('replaces terms of service URL with app URL', function () {
        $response = getJson('/api/v1/doc');

        $response->assertOk();
        $data = $response->json();

        if (isset($data['info']['termsOfService'])) {
            expect($data['info']['termsOfService'])->toContain('legal-notice');
        }
    });
});

describe('HTML response', function () {
    it('returns HTML view when not requesting JSON', function () {
        $response = get('/api/v1/doc');

        $response->assertOk();
    });
});

describe('error handling', function () {
    it('returns 500 when OpenAPI file does not exist', function () {
        File::shouldReceive('exists')
            ->once()
            ->with(resource_path('data/openapi.json'))
            ->andReturn(false);

        $response = getJson('/api/v1/doc');
        $response->assertStatus(500);
    });
});
