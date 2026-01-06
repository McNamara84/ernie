<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('extracts coverages from datacite-xml-example-full-v4.xml (with envelope tag)', function () {
    $this->actingAs(User::factory()->create());

    $xmlPath = base_path('tests/pest/dataset-examples/datacite-xml-example-full-v4.xml');
    $xmlContent = file_get_contents($xmlPath);

    $file = UploadedFile::fake()->createWithContent('datacite-xml-example-full-v4.xml', $xmlContent);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Get coverages from the session using the sessionKey
    $data = $response->json();
    $coverages = session()->get($data['sessionKey'])['coverages'] ?? [];

    expect($coverages)->toBeArray()
        ->toHaveCount(2);

    // First coverage should be "Test Coverage 1" with a bounding box
    expect($coverages[0])->toMatchArray([
        'description' => 'Test Coverage 1',
        'type' => 'box',
        'lonMin' => '11.659700',
        'lonMax' => '35.214400',
        'latMin' => '53.334500',
        'latMax' => '62.596100',
    ]);

    // Second coverage should be "Test Coverage 2" with a bounding box
    expect($coverages[1])->toMatchArray([
        'description' => 'Test Coverage 2',
        'type' => 'box',
        'lonMin' => '13.066000',
        'lonMax' => '25.019100',
        'latMin' => '50.068100',
        'latMax' => '63.707400',
    ]);
});
