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
    ]);

    $response->assertOk()->assertJson(['doi' => '10.1234/xyz']);
});
