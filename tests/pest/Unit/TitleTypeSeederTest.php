<?php

use App\Models\TitleType;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('title types are seeded', function () {
    $this->seed(TitleTypeSeeder::class);
    expect(TitleType::count())->toBe(5);
    expect(TitleType::where('active', true)->count())->toBe(5);
    expect(TitleType::where('elmo_active', true)->count())->toBe(0);
});
