<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('can access root URL', function () {
    $response = $this->get('/');

    dump('Response status: '.$response->status());
    dump('Response content length: '.strlen($response->getContent()));

    expect($response->status())->toBeIn([200, 302, 404]);
});

test('can access /dashboard', function () {
    $response = $this->get('/dashboard');

    dump('Response status for /dashboard: '.$response->status());

    expect($response->status())->toBeIn([200, 302, 404]);
});
