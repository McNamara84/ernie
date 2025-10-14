<?php

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns all title types', function () {
    $typeA = TitleType::create([
        'name' => 'Main',
        'slug' => 'main',
        'active' => true,
        'elmo_active' => true,
    ]);

    $typeB = TitleType::create([
        'name' => 'Alt',
        'slug' => 'alt',
        'active' => false,
        'elmo_active' => false,
    ]);

    $response = $this->getJson('/api/v1/title-types')->assertOk();

    $response->assertJsonCount(TitleType::count());
    $response->assertJsonFragment(['id' => $typeB->id, 'name' => 'Alt', 'slug' => 'alt']);
    $response->assertJsonStructure([
        '*' => ['id', 'name', 'slug'],
    ]);
});
