<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('debug route registration', function () {
    $routes = app('router')->getRoutes();

    dump('Total routes: '.$routes->count());

    $dashboardRoute = $routes->getByName('dashboard');
    $loginRoute = $routes->getByName('login');

    dump('Dashboard route exists: '.($dashboardRoute ? 'yes' : 'no'));
    dump('Login route exists: '.($loginRoute ? 'yes' : 'no'));

    if ($dashboardRoute) {
        dump('Dashboard URI: '.$dashboardRoute->uri());
    }

    if ($loginRoute) {
        dump('Login URI: '.$loginRoute->uri());
    }

    // Try to access dashboard route
    $dashboardUrl = route('dashboard');
    dump('Dashboard URL: '.$dashboardUrl);

    $response = $this->get($dashboardUrl);
    dump('Response status: '.$response->status());

    expect($routes->count())->toBeGreaterThan(0);
});
