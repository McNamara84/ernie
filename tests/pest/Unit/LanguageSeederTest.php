<?php

use App\Models\Language;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('languages are seeded', function () {
    $this->seed(LanguageSeeder::class);
    expect(Language::count())->toBe(3);
});
