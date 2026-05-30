<?php

declare(strict_types=1);

use function Pest\Laravel\getJson;

it('returns a minimal public health payload', function () {
    getJson('/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
        ]);
});