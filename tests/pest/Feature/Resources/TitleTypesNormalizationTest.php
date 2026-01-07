<?php

declare(strict_types=1);

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('title types ernie endpoint returns kebab-case slugs', function () {
    // Simulate legacy DB slugs (TitleCase)
    // Use firstOrCreate since migration may have already created MainTitle
    TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        [
            'name' => 'Main Title',
            'is_active' => true,
            'is_elmo_active' => true,
        ]
    );

    TitleType::create([
        'name' => 'Alternative Title',
        'slug' => 'AlternativeTitle',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    $response = $this->getJson('/api/v1/title-types/ernie')->assertOk();

    $response->assertJsonFragment(['slug' => 'main-title']);
    $response->assertJsonFragment(['slug' => 'alternative-title']);
});
