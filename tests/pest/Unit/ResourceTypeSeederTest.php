<?php

use App\Models\ResourceType;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('resource types are seeded', function () {
    $this->seed(ResourceTypeSeeder::class);
    expect(ResourceType::count())->toBe(32);
});
