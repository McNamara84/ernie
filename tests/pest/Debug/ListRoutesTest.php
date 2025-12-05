<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('list all registered routes', function () {
    $router = app('router');
    $routes = $router->getRoutes();
    
    dump("Total routes registered: " . $routes->count());
    
    $routeNames = [];
    foreach ($routes as $route) {
        if ($name = $route->getName()) {
            $routeNames[] = $name;
        }
    }
    
    dump("Named routes: " . implode(', ', array_slice($routeNames, 0, 20)));
    
    // Check specifically for dashboard
    $hasDashboard = in_array('dashboard', $routeNames);
    $hasLogin = in_array('login', $routeNames);
    
    dump("Has 'dashboard' route: " . ($hasDashboard ? 'YES' : 'NO'));
    dump("Has 'login' route: " . ($hasLogin ? 'YES' : 'NO'));
    
    expect($routes->count())->toBeGreaterThan(0);
});
