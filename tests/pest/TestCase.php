<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Vite;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Set up the test environment.
     *
     * Laravel's HTTP tests don't automatically handle CSRF tokens.
     * This disables CSRF validation globally for all tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (app()->environment('testing')) {
            $hotFile = storage_path('framework/vite.hot');

            if (! is_dir(dirname($hotFile))) {
                mkdir(dirname($hotFile), 0755, true);
            }

            if (! file_exists($hotFile)) {
                file_put_contents($hotFile, "http://localhost:5173");
            }

            Vite::useHotFile($hotFile);
        }

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
