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
        // Force APP_URL for testing before creating the app
        putenv('APP_URL=http://localhost');
        $_ENV['APP_URL'] = 'http://localhost';
        $_SERVER['APP_URL'] = 'http://localhost';

        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
