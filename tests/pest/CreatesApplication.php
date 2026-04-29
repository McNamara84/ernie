<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // Force test environment variables before Laravel bootstraps. This is required
        // because Docker containers (e.g. ernie-app-dev) inject runtime env vars such as
        // APP_ENV=local and DB_CONNECTION=mysql at the OS level, which take precedence
        // over PHPUnit's <env> declarations in phpunit.xml (those are not applied with
        // force="true" reliably across Pest/PHPUnit versions). Forcing here guarantees
        // tests always run against the in-memory SQLite database, regardless of the
        // shell environment they are invoked from.
        $forced = [
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://localhost',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ];

        foreach ($forced as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
