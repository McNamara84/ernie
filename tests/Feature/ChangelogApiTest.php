<?php

use function Pest\Laravel\getJson;

it('returns changelog data', function () {
    getJson('/api/changelog')
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Interaktive Timeline',
        ]);
});
