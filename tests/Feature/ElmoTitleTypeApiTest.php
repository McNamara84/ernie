<?php

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('returns only title types enabled for ELMO', function () {
    $enabled = TitleType::create([
        'name' => 'Main',
        'slug' => 'main',
        'active' => true,
        'elmo_active' => true,
    ]);

    TitleType::create([
        'name' => 'Alt',
        'slug' => 'alt',
        'active' => true,
        'elmo_active' => false,
    ]);

    $response = getJson('/api/v1/title-types/elmo')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Main', 'slug' => 'main']);
});
