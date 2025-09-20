<?php

namespace App\Providers;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->ensureCiConsoleUsesSqlite();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function ensureCiConsoleUsesSqlite(): void
    {
        if (!App::runningInConsole()) {
            return;
        }

        if (! $this->isCiEnvironment()) {
            return;
        }

        if ($this->usesSqliteConnection()) {
            return;
        }

        $sqlitePath = $this->resolveSqlitePath();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', $sqlitePath);

        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $sqlitePath);
    }

    private function isCiEnvironment(): bool
    {
        return filter_var(Env::get('CI', false), FILTER_VALIDATE_BOOL);
    }

    private function usesSqliteConnection(): bool
    {
        return strtolower((string) Env::get('DB_CONNECTION', 'sqlite')) === 'sqlite';
    }

    private function resolveSqlitePath(): string
    {
        $configured = Env::get('DB_DATABASE');

        if ($configured === ':memory:') {
            return ':memory:';
        }

        $path = database_path('database.sqlite');

        if (is_string($configured) && $configured !== '' && $this->looksLikeSqlitePath($configured)) {
            $path = $this->isAbsolutePath($configured)
                ? $configured
                : base_path($configured);
        }

        $this->ensureSqliteDatabaseExists($path);

        return $path;
    }

    private function looksLikeSqlitePath(string $path): bool
    {
        $normalized = strtolower($path);

        return str_contains($path, '/')
            || str_contains($path, '\\')
            || str_ends_with($normalized, '.sqlite');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || str_starts_with($path, '\\')) {
            return true;
        }

        return strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':';
    }

    private function ensureSqliteDatabaseExists(string $path): void
    {
        if ($path === ':memory:') {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory for SQLite database at [%s].', $directory));
        }

        if (! file_exists($path) && ! touch($path)) {
            throw new \RuntimeException(sprintf('Unable to create SQLite database file at [%s].', $path));
        }
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        $repository = Env::getRepository();
        $repository->set($key, $value);

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
