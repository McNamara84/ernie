<?php

use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists all licenses', function () {
    License::create(['identifier' => 'MIT', 'name' => 'MIT License']);

    $this->getJson('/api/v1/licenses')
        ->assertOk()
        ->assertJsonCount(1);
});

it('lists ELMO-active licenses', function () {
    License::create(['identifier' => 'MIT', 'name' => 'MIT License', 'active' => true, 'elmo_active' => true]);
    License::create(['identifier' => 'Apache', 'name' => 'Apache', 'active' => true, 'elmo_active' => false]);

    $this->getJson('/api/v1/licenses/elmo')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', 'MIT');
});

it('lists ERNIE-active licenses', function () {
    License::create(['identifier' => 'MIT', 'name' => 'MIT License', 'active' => true]);
    License::create(['identifier' => 'Apache', 'name' => 'Apache', 'active' => false]);

    $this->getJson('/api/v1/licenses/ernie')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', 'MIT');
});
