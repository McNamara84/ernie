<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

it('returns cached ROR affiliations when available', function () {
    Storage::fake('local');

    $data = [
        ['value' => 'Example University', 'rorId' => 'https://ror.org/123'],
        ['value' => 'Sample Institute', 'rorId' => 'https://ror.org/456'],
    ];

    Storage::disk('local')->put('ror/ror-affiliations.json', json_encode($data, JSON_THROW_ON_ERROR));

    $response = $this->getJson('/api/v1/ror-affiliations');

    $response->assertOk()->assertExactJson($data);
});

it('returns an empty array when the cache is missing', function () {
    Storage::fake('local');

    $response = $this->getJson('/api/v1/ror-affiliations');

    $response->assertOk()->assertExactJson([]);
});

it('responds with an error when the cache is invalid', function () {
    Storage::fake('local');
    Log::spy();

    Storage::disk('local')->put('ror/ror-affiliations.json', '{invalid');

    $response = $this->getJson('/api/v1/ror-affiliations');

    $response->assertStatus(500)->assertExactJson([]);

    Log::shouldHaveReceived('error')->once();
});
