<?php

declare(strict_types=1);

use App\Models\User;

test('returns vocabulary url from config', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/vocabularies/msl-vocabulary-url');

    $response->assertStatus(200);
    $response->assertJsonStructure(['url']);

    $url = $response->json('url');
    expect($url)
        ->toBeString()
        ->toStartWith('https://')
        ->toContain('laboratories.json');
});
