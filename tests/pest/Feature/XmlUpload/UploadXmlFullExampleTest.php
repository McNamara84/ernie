<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('extracts coverages from datacite-example-full-v4.xml', function () {
    $this->actingAs(User::factory()->create());

    $xmlPath = base_path('tests/pest/dataset-examples/datacite-example-full-v4.xml');
    $xmlContent = file_get_contents($xmlPath);
    
    $file = UploadedFile::fake()->createWithContent('datacite-example-full-v4.xml', $xmlContent);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Debug: Show what we got
    $coverages = $response->json('coverages');
    dump('Coverages:', $coverages);
    
    $dates = $response->json('dates');
    dump('Dates:', $dates);

    expect($coverages)->toBeArray();
});
