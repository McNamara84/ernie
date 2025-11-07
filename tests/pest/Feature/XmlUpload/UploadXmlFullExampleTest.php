<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('extracts coverages from datacite-xml-example-full-v4.xml', function () {
    $this->actingAs(User::factory()->create());

    $xmlPath = base_path('tests/pest/dataset-examples/datacite-xml-example-full-v4.xml');
    $xmlContent = file_get_contents($xmlPath);

    $file = UploadedFile::fake()->createWithContent('datacite-xml-example-full-v4.xml', $xmlContent);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Debug: Show what we got
    $coverages = $response->sessionData('coverages');
    dump('Coverages:', $coverages);

    $dates = $response->sessionData('dates');
    dump('Dates:', $dates);

    expect($coverages)->toBeArray();
});
