<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('normalizes ORCID from full URL to identifier only', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Test, Author</creatorName>
      <givenName>Author</givenName>
      <familyName>Test</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">https://orcid.org/0000-0001-5727-2427</nameIdentifier>
    </creator>
  </creators>
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName nameType="Personal">Test, Contributor</contributorName>
      <givenName>Contributor</givenName>
      <familyName>Test</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">https://orcid.org/0000-0002-9876-5432</nameIdentifier>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('orcid-test.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Author ORCID should be normalized (URL prefix removed)
    $response->assertSessionDataPath('authors.0.orcid', '0000-0001-5727-2427');

    // Contributor ORCID should also be normalized
    $response->assertSessionDataPath('contributors.0.orcid', '0000-0002-9876-5432');
});

test('preserves already-normalized ORCID without URL prefix', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Test, Author</creatorName>
      <givenName>Author</givenName>
      <familyName>Test</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">0000-0001-5727-2427</nameIdentifier>
    </creator>
  </creators>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('orcid-normalized-test.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // ORCID should remain as-is (already in correct format)
    $response->assertSessionDataPath('authors.0.orcid', '0000-0001-5727-2427');
});
