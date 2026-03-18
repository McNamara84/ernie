<?php

declare(strict_types=1);

use App\Http\Controllers\RorAffiliationController;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

covers(RorAffiliationController::class);

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
});

it('returns empty array when ROR file does not exist', function () {
    $this->getJson('/api/v1/ror-affiliations')
        ->assertOk()
        ->assertJson([]);
});

it('returns flat array data from ROR file', function () {
    $data = [
        ['id' => 'https://ror.org/04z8jg394', 'name' => 'GFZ German Research Centre for Geosciences'],
        ['id' => 'https://ror.org/05dxps526', 'name' => 'Max Planck Society'],
    ];

    Storage::disk('local')->put('ror/ror-affiliations.json', json_encode($data));

    $this->getJson('/api/v1/ror-affiliations')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonFragment(['name' => 'GFZ German Research Centre for Geosciences']);
});

it('returns wrapped data format from ROR file', function () {
    $wrapped = [
        'lastUpdated' => '2024-01-01',
        'total' => 2,
        'data' => [
            ['id' => 'https://ror.org/04z8jg394', 'name' => 'GFZ'],
            ['id' => 'https://ror.org/05dxps526', 'name' => 'MPG'],
        ],
    ];

    Storage::disk('local')->put('ror/ror-affiliations.json', json_encode($wrapped));

    $this->getJson('/api/v1/ror-affiliations')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonFragment(['name' => 'GFZ']);
});

it('returns empty array for invalid JSON in ROR file', function () {
    Storage::disk('local')->put('ror/ror-affiliations.json', 'not valid json {{{');

    $this->getJson('/api/v1/ror-affiliations')
        ->assertOk()
        ->assertJson([]);
});

it('is accessible without authentication', function () {
    $this->getJson('/api/v1/ror-affiliations')
        ->assertOk();
});
