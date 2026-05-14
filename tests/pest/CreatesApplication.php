<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Resolve the database connection that Pest should force before Laravel boots.
     *
     * The default local loop stays on SQLite in memory for speed. A dedicated
     * MySQL-compatible slice can opt in via `ERNIE_TEST_DB_*` variables so
     * driver-sensitive tests run against an isolated Docker database instead of
     * the regular development schema.
     *
     * @return array<string, string>
     */
    private function forcedEnvironment(): array
    {
        $driver = (string) (getenv('ERNIE_TEST_DB_CONNECTION') ?: 'sqlite');

        if ($driver === 'sqlite') {
            return [
                'APP_ENV' => 'testing',
                'APP_URL' => 'http://localhost',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
            ];
        }

        return [
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://localhost',
            'DB_CONNECTION' => $driver,
            'DB_HOST' => (string) (getenv('ERNIE_TEST_DB_HOST') ?: 'db'),
            'DB_PORT' => (string) (getenv('ERNIE_TEST_DB_PORT') ?: '3306'),
            'DB_DATABASE' => (string) (getenv('ERNIE_TEST_DB_DATABASE') ?: 'ernie_test'),
            'DB_USERNAME' => (string) (getenv('ERNIE_TEST_DB_USERNAME') ?: 'ernie'),
            'DB_PASSWORD' => (string) (getenv('ERNIE_TEST_DB_PASSWORD') ?: 'secret'),
        ];
    }

    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // Force test environment variables before Laravel bootstraps. This is required
        // because Docker containers (e.g. ernie-app-dev) inject runtime env vars such as
        // APP_ENV=local and DB_CONNECTION=mysql at the OS level, which take precedence
        // over PHPUnit's <env> declarations in phpunit.xml (those are not applied with
        // force="true" reliably across Pest/PHPUnit versions). Forcing here keeps the
        // default local loop on in-memory SQLite while still allowing the dedicated
        // MySQL-sensitive slice to opt in via ERNIE_TEST_DB_* variables.
        $forced = $this->forcedEnvironment();

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
