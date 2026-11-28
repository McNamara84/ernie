<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;

trait WithTemporaryLogStorage
{
    protected function setUpWithTemporaryLogStorage(): void
    {
        $originalStorage = storage_path();
        $temporaryStorage = sys_get_temp_dir().'/ernie-log-test-'.bin2hex(random_bytes(16));
        File::ensureDirectoryExists($temporaryStorage.'/logs');
        app()->useStoragePath($temporaryStorage);
        config([
            'logging.default' => 'test-log',
            'logging.channels.test-log' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log')],
        ]);

        $this->beforeApplicationDestroyed(static function () use ($originalStorage, $temporaryStorage): void {
            app()->useStoragePath($originalStorage);
            File::deleteDirectory($temporaryStorage);
        });
    }
}
