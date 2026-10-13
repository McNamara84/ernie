<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;

uses()->group('browser', 'api-doc');

it('renders the built Swagger UI in the CI testing environment', function (): void {
    expect(app()->environment())->toBe('testing');
    app(Vite::class)->useHotFile(storage_path('framework/testing-vite.hot'))->useBuildDirectory('build');

    visit('/api/v1/doc')
        ->assertSee('ERNIE API')
        ->assertSee('1.1.0')
        ->assertSee('OAS 3.2')
        ->assertCount('#swagger-ui .opblock-summary-path[data-path*="elmo-msl"]', 27)
        ->assertNotPresent('#swagger-ui .fallback')
        ->assertNotPresent('#swagger-ui .errors-wrapper')
        ->assertNoSmoke();
});
