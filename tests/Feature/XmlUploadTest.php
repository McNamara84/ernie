<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('extracts doi from uploaded xml', function () {
    $this->actingAs(User::factory()->create());

    $xml = '<resource><identifier identifierType="DOI">10.1234/xyz</identifier></resource>';
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertOk()->assertJson(['doi' => '10.1234/xyz']);
});

it('returns error when doi is missing', function () {
    $this->actingAs(User::factory()->create());

    $xml = '<resource></resource>';
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJson(['message' => 'DOI not found']);
});

it('validates xml file type and size', function () {
    $this->actingAs(User::factory()->create());

    $file = UploadedFile::fake()->create('test.txt', 10, 'text/plain');

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJsonValidationErrors('file');
});
