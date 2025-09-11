<?php

use App\Models\User;
use App\Models\ResourceType;
use Illuminate\Http\UploadedFile;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('extracts doi, publication year, version, language, resource type and titles from uploaded xml, ignoring related item titles', function () {
    $this->actingAs(User::factory()->create());
    ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);

    $xml = '<resource><identifier identifierType="DOI">10.1234/xyz</identifier><publicationYear>2024</publicationYear><version>1'
        . '.0</version><language>de</language><titles><title>Example Title</title><title titleType="Subtitle">Example Subtitle</title>'
        . '<title titleType="TranslatedTitle">Example TranslatedTitle</title><title titleType="AlternativeTitle">Example AlternativeTitle</title></titles>'
        . '<relatedItem><titles><title>Example RelatedItem Title</title><title titleType="TranslatedTitle">Example RelatedItem TranslatedTitle</title></titles></relatedItem>'
        . '<resourceType resourceTypeGeneral="Dataset">Dataset</resourceType></resource>';
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertOk()->assertJson([
        'doi' => '10.1234/xyz',
        'year' => '2024',
        'version' => '1.0',
        'language' => 'de',
        'resourceType' => 'dataset',
        'titles' => [
            ['title' => 'Example Title', 'titleType' => 'main-title'],
            ['title' => 'Example Subtitle', 'titleType' => 'subtitle'],
            ['title' => 'Example TranslatedTitle', 'titleType' => 'translated-title'],
            ['title' => 'Example AlternativeTitle', 'titleType' => 'alternative-title'],
        ],
    ]);
});

it('returns null when doi, publication year, version, language and resource type are missing', function () {
    $this->actingAs(User::factory()->create());

    $xml = '<resource></resource>';
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertOk()->assertJson(['doi' => null, 'year' => null, 'version' => null, 'language' => null, 'resourceType' => null, 'titles' => []]);
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
