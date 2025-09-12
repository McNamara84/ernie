<?php

use function Pest\Laravel\getJson;

it('returns changelog data grouped by release', function () {
    getJson('/api/changelog')
        ->assertOk()
        ->assertJsonFragment([
            'version' => '0.1.0',
        ])
        ->assertJsonFragment([
            'title' => 'Resource Information form group',
        ])
        ->assertJsonFragment([
            'title' => 'License and Rights',
        ]);
});
