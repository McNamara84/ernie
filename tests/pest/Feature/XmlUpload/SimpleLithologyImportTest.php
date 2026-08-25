<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('cgi-simple-lithology.json', json_encode([
        'data' => [[
            'id' => 'http://resource.geosciml.org/classifier/cgi/lithology/material',
            'text' => 'Material',
            'scheme' => 'CGI Simple Lithology',
            'schemeURI' => config('simple_lithology.scheme_uri'),
            'children' => [[
                'id' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt',
                'text' => 'Basalt',
                'scheme' => 'CGI Simple Lithology',
                'schemeURI' => config('simple_lithology.scheme_uri'),
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));
});

it('imports a path-only Simple Lithology XML subject and resolves its concept URI', function (): void {
    $this->actingAs(User::factory()->create());
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="CGI Simple Lithology">Material &amp;gt; Basalt</subject>
  </subjects>
</resource>
XML;

    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => UploadedFile::fake()->createWithContent('simple-lithology.xml', $xml),
    ])->assertOk();

    $response->assertSessionDataPath('gcmdKeywords.0.id', 'http://resource.geosciml.org/classifier/cgi/lithology/basalt');
    $response->assertSessionDataPath('gcmdKeywords.0.path', 'Material > Basalt');
    $response->assertSessionDataPath('gcmdKeywords.0.scheme', 'CGI Simple Lithology');
});

it('preserves an unresolved path-only Simple Lithology XML subject for review', function (): void {
    $this->actingAs(User::factory()->create());
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="CGI Simple Lithology">Material &gt; Historical rock</subject>
  </subjects>
</resource>
XML;

    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => UploadedFile::fake()->createWithContent('legacy-lithology.xml', $xml),
    ])->assertOk();

    expect($response->sessionData('gcmdKeywords.0.id'))->toStartWith('legacy:');
    $response->assertSessionDataPath('gcmdKeywords.0.isLegacy', true);
    $response->assertSessionDataPath('gcmdKeywords.0.path', 'Material > Historical rock');
});
