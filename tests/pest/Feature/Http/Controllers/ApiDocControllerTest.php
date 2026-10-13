<?php

declare(strict_types=1);

use App\Http\Controllers\ApiDocController;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

covers(ApiDocController::class);

describe('JSON response', function () {
    it('returns OpenAPI spec as JSON when JSON is requested', function () {
        $response = getJson('/api/v1/doc');

        $response->assertOk()
            ->assertJsonStructure(['openapi', 'info' => ['title', 'summary'], 'paths', 'servers'])
            ->assertJsonPath('openapi', '3.2.1')
            ->assertJsonPath('info.summary', 'Read-only metadata, vocabulary, and citation endpoints for ERNIE integrations.');
    });

    it('contains app URL in server configuration', function () {
        $response = getJson('/api/v1/doc');
        $appUrl = rtrim((string) config('app.url'), '/');

        $response->assertOk();
        $data = $response->json();

        expect($data['servers'][0]['url'])->toBe($appUrl);
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

    it('loads the built Swagger assets in every application environment', function (string $environment): void {
        $publicPath = sys_get_temp_dir().'/ernie-swagger-'.Str::uuid();
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', json_encode([
            'resources/css/app.css' => ['file' => 'assets/app-ci-test.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
            'resources/js/swagger.tsx' => ['file' => 'assets/swagger-ci-test.js', 'src' => 'resources/js/swagger.tsx', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));

        $this->app->usePublicPath($publicPath);
        $this->app->detectEnvironment(fn (): string => $environment);
        $this->app->instance(Vite::class, (new Vite)->useHotFile($publicPath.'/hot'));

        try {
            get('/api/v1/doc')->assertOk()
                ->assertSee('rel="stylesheet"', false)
                ->assertSee('/build/assets/app-ci-test.css', false)
                ->assertSee('type="module"', false)
                ->assertSee('/build/assets/swagger-ci-test.js', false)
                ->assertSee('window.__spec__', false);
        } finally {
            File::deleteDirectory($publicPath);
        }
    })->with(['local', 'testing', 'production']);
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
