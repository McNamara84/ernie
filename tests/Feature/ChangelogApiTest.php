<?php

use function Pest\Laravel\getJson;

it('returns changelog data grouped by release', function () {
    getJson('/api/changelog')
        ->assertOk()
        ->assertJsonFragment([
            'version' => '1.0.0',
        ])
        ->assertJsonFragment([
            'title' => 'Interaktive Timeline',
        ])
        ->assertJsonFragment([
            'title' => 'Fixed accessibility issues',
        ]);
});
