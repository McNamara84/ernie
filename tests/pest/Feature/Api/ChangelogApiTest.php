<?php

use Illuminate\Support\Facades\File;

use function Pest\Laravel\getJson;

it('returns changelog data grouped by release', function () {
    getJson('/api/changelog')
        ->assertOk()
        ->assertJsonFragment([
            'version' => '0.1.0',
        ])
        ->assertJsonFragment([
            'title' => 'Resources workspace',
        ])
        ->assertJsonFragment([
            'title' => 'Dashboard overview',
        ]);
});

it('returns an error when changelog JSON is invalid', function () {
    $path = resource_path('data/changelog.json');
    $original = File::get($path);
    File::put($path, '{invalid');
    try {
        getJson('/api/changelog')
            ->assertStatus(500)
            ->assertJson([
                'error' => 'Invalid changelog data',
            ]);
    } finally {
        File::put($path, $original);
    }
});
