<?php

use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('returns only resource types enabled for ELMO', function () {
    $enabled = ResourceType::create([
        'name' => 'Type A',
        'slug' => 'type-a',
        'active' => true,
        'elmo_active' => true,
    ]);

    ResourceType::create([
        'name' => 'Type B',
        'slug' => 'type-b',
        'active' => true,
        'elmo_active' => false,
    ]);

    $response = getJson('/api/v1/resource-types/elmo')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Type A', 'slug' => 'type-a']);
});
