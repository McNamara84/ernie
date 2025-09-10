<?php

use App\Models\TitleType;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('title types are seeded', function () {
    $this->seed(TitleTypeSeeder::class);
    expect(TitleType::count())->toBe(5);
});
