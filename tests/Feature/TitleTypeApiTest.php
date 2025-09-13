<?php

use App\Models\TitleType;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns active title types for Ernie', function () {
    $this->seed(TitleTypeSeeder::class);
    TitleType::create(['name' => 'Inactive', 'slug' => 'inactive', 'active' => false]);

    $response = $this->getJson('/api/v1/title-types/ernie')->assertOk();

    $response->assertJsonCount(TitleType::where('active', true)->count());
    $response->assertJsonStructure([
        '*' => ['id', 'name', 'slug'],
    ]);
});
