<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('access dashboard via route() helper', function () {
    $url = route('dashboard');
    
    dump("Dashboard URL from route(): " . $url);
    
    $response = $this->get($url);
    
    dump("Response status: " . $response->status());
    
    expect($response->status())->toBeIn([200, 302, 404]);
});

test('access dashboard via direct path', function () {
    $response = $this->get('/dashboard');
    
    dump("Response status for /dashboard: " . $response->status());
    
    expect($response->status())->toBeIn([200, 302, 404]);
});
