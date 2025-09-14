<?php

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists all languages', function () {
    Language::create(['code' => 'en', 'name' => 'English']);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonCount(1);
});

it('lists ELMO-active languages', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::create(['code' => 'de', 'name' => 'German', 'active' => true, 'elmo_active' => false]);

    $this->getJson('/api/v1/languages/elmo')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.code', 'en');
});

it('lists ERNIE-active languages', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'active' => true]);
    Language::create(['code' => 'de', 'name' => 'German', 'active' => false]);

    $this->getJson('/api/v1/languages/ernie')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.code', 'en');
});
