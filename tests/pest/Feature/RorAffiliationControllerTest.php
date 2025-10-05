<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

it('returns cached ROR affiliations when available', function () {
    Storage::fake('local');

    $data = [
        ['prefLabel' => 'Example University', 'rorId' => 'https://ror.org/123', 'otherLabel' => ['Example University']],
        ['prefLabel' => 'Sample Institute', 'rorId' => 'https://ror.org/456', 'otherLabel' => ['Sample Institute']],
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

it('responds with an error when the storage adapter returns non-string contents', function () {
    Log::spy();

    $filesystem = \Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->with('ror/ror-affiliations.json')->andReturnTrue();
    $filesystem->shouldReceive('get')->once()->with('ror/ror-affiliations.json')->andReturn(null);

    Storage::shouldReceive('disk')->once()->with('local')->andReturn($filesystem);

    $response = $this->getJson('/api/v1/ror-affiliations');

    $response->assertStatus(500)->assertExactJson([]);

    Log::shouldHaveReceived('warning')->once();
});

it('returns large datasets efficiently', function () {
    Storage::fake('local');

    // Simulate a large dataset similar to real ROR data
    $data = [];
    for ($i = 0; $i < 1000; $i++) {
        $data[] = [
            'prefLabel' => "University {$i}",
            'rorId' => "https://ror.org/{$i}",
            'otherLabel' => ["Uni {$i}", "University {$i}"],
        ];
    }

    Storage::disk('local')->put('ror/ror-affiliations.json', json_encode($data, JSON_THROW_ON_ERROR));

    $response = $this->getJson('/api/v1/ror-affiliations');

    $response->assertOk()
        ->assertJsonCount(1000)
        ->assertJsonStructure([
            '*' => ['prefLabel', 'rorId', 'otherLabel'],
        ]);
});

it('handles special characters in organization names correctly', function () {
    Storage::fake('local');

    $data = [
        [
            'prefLabel' => 'École Polytechnique Fédérale de Lausanne',
            'rorId' => 'https://ror.org/02s6k3f65',
            'otherLabel' => ['EPFL', 'École Polytechnique'],
        ],
        [
            'prefLabel' => 'Universität für Bodenkultur Wien',
            'rorId' => 'https://ror.org/03prydq77',
            'otherLabel' => ['BOKU'],
        ],
    ];

    Storage::disk('local')->put('ror/ror-affiliations.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    $response = $this->getJson('/api/v1/ror-affiliations');

    $response->assertOk()
        ->assertJson($data);
});

it('returns correct content-type header', function () {
    Storage::fake('local');

    $data = [['prefLabel' => 'Test', 'rorId' => 'https://ror.org/123', 'otherLabel' => []]];
    Storage::disk('local')->put('ror/ror-affiliations.json', json_encode($data));

    $response = $this->getJson('/api/v1/ror-affiliations');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json');
});

