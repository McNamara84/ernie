<?php

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Vite;

it('configures vite to use the assets build directory', function () {
    $app = new Application(dirname(__DIR__, 2));
    Facade::setFacadeApplication($app);

    Vite::shouldReceive('useBuildDirectory')->once()->with('assets');

    $provider = new AppServiceProvider($app);
    $provider->boot();
});
