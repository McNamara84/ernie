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

        if (is_string($configured) && $configured !== '') {
            return $this->isAbsolutePath($configured)
                ? $configured
                : base_path($configured);
        }

        return database_path('database.sqlite');
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

    private function setEnvironmentValue(string $key, string $value): void
    {
        $repository = Env::getRepository();
        $repository->set($key, $value);

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
