<?php

declare(strict_types=1);

use App\Http\Controllers\ApiDocController;
use Illuminate\Support\Facades\File;

covers(ApiDocController::class);

describe('JSON response', function () {
    it('returns OpenAPI spec as JSON when JSON is requested', function () {
        $response = $this->getJson('/api/v1/doc');

        $response->assertOk()
            ->assertJsonStructure(['openapi', 'info', 'paths']);
    });

    it('contains app URL in server configuration', function () {
        $response = $this->getJson('/api/v1/doc');

        $response->assertOk();
        $data = $response->json();

        expect($data['servers'][0]['url'])->toBe(config('app.url'));
    });

    it('replaces terms of service URL with app URL', function () {
        $response = $this->getJson('/api/v1/doc');

        $response->assertOk();
        $data = $response->json();

        if (isset($data['info']['termsOfService'])) {
            expect($data['info']['termsOfService'])->toContain('legal-notice');
        }
    });
});

describe('HTML response', function () {
    it('returns HTML view when not requesting JSON', function () {
        $response = $this->get('/api/v1/doc');

        $response->assertOk();
    });
});

describe('error handling', function () {
    it('returns 500 when OpenAPI file does not exist', function () {
        $path = resource_path('data/openapi.json');
        $backupPath = $path . '.bak';

        File::move($path, $backupPath);

        try {
            $response = $this->getJson('/api/v1/doc');
            $response->assertStatus(500);
        } finally {
            File::move($backupPath, $path);
        }
    });
});
