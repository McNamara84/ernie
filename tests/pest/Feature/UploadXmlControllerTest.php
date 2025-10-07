<?php

use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns resource type id from uploaded XML', function () {
    $this->actingAs(User::factory()->create());

    $type = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
        'active' => true,
        'elmo_active' => true,
    ]);

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonPath('resourceType', (string) $type->id);
});

test('extracts contributors from uploaded XML', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName nameType="Personal">ExampleFamilyName, ExampleGivenName</contributorName>
      <givenName>ExampleGivenName</givenName>
      <familyName>ExampleFamilyName</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">https://orcid.org/0000-0001-5727-2427</nameIdentifier>
      <affiliation affiliationIdentifier="https://ror.org/04wxnsj81" affiliationIdentifierScheme="ROR" schemeURI="https://ror.org">ExampleAffiliation</affiliation>
    </contributor>
    <contributor contributorType="Distributor">
      <contributorName nameType="Organizational">ExampleOrganization</contributorName>
      <nameIdentifier nameIdentifierScheme="ROR" schemeURI="https://ror.org">https://ror.org/03yrm5c26</nameIdentifier>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('contributors.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonPath('contributors.0.type', 'person');
    $response->assertJsonPath('contributors.0.roles', ['ContactPerson']);
    $response->assertJsonPath('contributors.0.orcid', 'https://orcid.org/0000-0001-5727-2427');
    $response->assertJsonPath('contributors.0.firstName', 'ExampleGivenName');
    $response->assertJsonPath('contributors.0.lastName', 'ExampleFamilyName');
    $response->assertJsonPath('contributors.0.affiliations.0.value', 'ExampleAffiliation');
    $response->assertJsonPath('contributors.0.affiliations.0.rorId', 'https://ror.org/04wxnsj81');

    $response->assertJsonPath('contributors.1.type', 'institution');
    $response->assertJsonPath('contributors.1.roles', ['Distributor']);
    $response->assertJsonPath('contributors.1.institutionName', 'ExampleOrganization');
    $response->assertJsonPath('contributors.1.affiliations.0.value', 'ExampleOrganization');
    $response->assertJsonPath('contributors.1.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
});

test('uploading a non-xml file returns validation errors', function () {
    $this->actingAs(User::factory()->create());

    $file = UploadedFile::fake()->create('test.txt', 10, 'text/plain');

    $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertStatus(422)
        ->assertInvalid('file');
});
