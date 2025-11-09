<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(\App\Models\User::factory()->create(['role' => 'curator']));
});

it('can upload XML file and returns session key', function () {
    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <titles>
        <title>Test Dataset</title>
    </titles>
    <creators>
        <creator>
            <creatorName nameType="Personal">Doe, John</creatorName>
        </creator>
    </creators>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => $file,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['sessionKey']);

    $sessionKey = $response->json('sessionKey');
    expect($sessionKey)->toStartWith('xml_upload_');
});

it('validates XML file is required', function () {
    $response = $this->postJson('/dashboard/upload-xml', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('validates XML file has correct extension', function () {
    $file = UploadedFile::fake()->create('test.txt', 100);

    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => $file,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('validates XML file size limit', function () {
    // Create file larger than 10MB
    $file = UploadedFile::fake()->create('test.xml', 11000);

    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => $file,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('stores XML data in session with random key', function () {
    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <titles>
        <title>Test Dataset</title>
    </titles>
    <creators>
        <creator>
            <creatorName nameType="Personal">Doe, John</creatorName>
        </creator>
    </creators>
    <publicationYear>2024</publicationYear>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => $file,
    ]);

    $response->assertStatus(200);

    $sessionKey = $response->json('sessionKey');

    // Check that session contains the data
    expect(Session::has($sessionKey))->toBeTrue();

    $sessionData = Session::get($sessionKey);
    expect($sessionData)->toBeArray()
        ->and($sessionData)->toHaveKey('titles')
        ->and($sessionData)->toHaveKey('authors');
});

it('can load editor with xml session parameter', function () {
    // First, manually store XML data in session
    $sessionKey = 'xml_upload_'.uniqid();
    $xmlData = [
        'titles' => [['title' => 'Test Title', 'titleType' => 'main-title']],
        'authors' => [['type' => 'person', 'firstName' => 'John', 'lastName' => 'Doe']],
        'descriptions' => [],
        'licenses' => [],
        'dates' => [],
        'coverages' => [],
        'gcmdKeywords' => [],
        'freeKeywords' => [],
        'mslKeywords' => [],
        'fundingReferences' => [],
        'contributors' => [],
        'mslLaboratories' => [],
        'doi' => null,
        'year' => '2024',
        'version' => '1.0',
        'language' => 'en',
        'resourceType' => null,
    ];

    Session::put($sessionKey, $xmlData);

    $response = $this->get('/editor?xmlSession='.$sessionKey);

    $response->assertStatus(200);

    // Session should be cleared after loading
    expect(Session::has($sessionKey))->toBeFalse();
});

it('rejects invalid xml session key format', function () {
    $response = $this->get('/editor?xmlSession=invalid_key');

    // Security: Editor must reject session keys that don't start with 'xml_upload_' prefix
    $response->assertStatus(400);
    expect($response->exception->getMessage())->toBe('Invalid session key format');
});

it('rejects non-existent xml session key', function () {
    $response = $this->get('/editor?xmlSession=xml_upload_nonexistent123');

    // Editor rejects non-existent keys with redirect (not 404)
    $response->assertRedirect();
});

it('handles large XML files without uri parameter overflow', function () {
    // Create a large XML with many elements to simulate realistic dataset
    $titles = '';
    for ($i = 1; $i <= 10; $i++) {
        $titles .= '<title>Test Title '.$i.'</title>'.PHP_EOL;
    }

    $creators = '';
    for ($i = 1; $i <= 20; $i++) {
        $creators .= <<<XML
        <creator>
            <creatorName nameType="Personal">Lastname{$i}, Firstname{$i}</creatorName>
            <givenName>Firstname{$i}</givenName>
            <familyName>Lastname{$i}</familyName>
            <nameIdentifier nameIdentifierScheme="ORCID">0000-0001-2345-678{$i}</nameIdentifier>
            <affiliation>Test Institution {$i}</affiliation>
        </creator>
XML;
    }

    $descriptions = '';
    for ($i = 1; $i <= 5; $i++) {
        $text = str_repeat('This is a long description text. ', 100);
        $descriptions .= '<description descriptionType="Abstract">'.$text.'</description>'.PHP_EOL;
    }

    $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <titles>{$titles}</titles>
    <creators>{$creators}</creators>
    <publicationYear>2024</publicationYear>
    <descriptions>{$descriptions}</descriptions>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('large-test.xml', $xmlContent);

    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => $file,
    ]);

    // Should succeed without 414 error
    $response->assertStatus(200)
        ->assertJsonStructure(['sessionKey']);

    // Verify session contains large data
    $sessionKey = $response->json('sessionKey');
    expect(Session::has($sessionKey))->toBeTrue();
});

it('rejects invalid oldDatasetId parameter', function () {
    // Test negative ID
    $response = $this->get('/editor?oldDatasetId=-1');
    $response->assertStatus(400);
    expect($response->exception->getMessage())->toBe('Invalid dataset ID');

    // Test zero
    $response = $this->get('/editor?oldDatasetId=0');
    $response->assertStatus(400);

    // Test non-numeric string
    $response = $this->get('/editor?oldDatasetId=abc');
    $response->assertStatus(400);
});

it('rejects tampered xml session data with invalid structure', function () {
    // Create a session with tampered data (wrong types)
    $sessionKey = 'xml_upload_'.Str::random(40);

    // Tamper with data structure - titles should be array but set as string
    Session::put($sessionKey, [
        'doi' => '10.1234/test',
        'year' => '2024',
        'titles' => 'This should be an array', // Invalid: string instead of array
        'licenses' => [],
        'authors' => [],
    ]);

    $response = $this->get('/editor?xmlSession='.$sessionKey);

    // Should reject invalid structure
    $response->assertStatus(400);
    expect($response->exception->getMessage())->toContain('Invalid session data structure');
});

it('rejects tampered xml session data with invalid scalar types', function () {
    // Create a session with tampered data (wrong types)
    $sessionKey = 'xml_upload_'.Str::random(40);

    // Tamper with data structure - year should be string/numeric but set as array
    Session::put($sessionKey, [
        'doi' => '10.1234/test',
        'year' => ['invalid' => 'array'], // Invalid: array instead of string/numeric
        'titles' => [],
        'licenses' => [],
        'authors' => [],
    ]);

    $response = $this->get('/editor?xmlSession='.$sessionKey);

    // Should reject invalid structure
    $response->assertStatus(400);
    expect($response->exception->getMessage())->toContain('Invalid session data structure');
});
