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
    <contributor contributorType="WorkPackageLeader">
      <contributorName nameType="Personal">ExampleLeaderFamily, ExampleLeaderGiven</contributorName>
      <givenName>ExampleLeaderGiven</givenName>
      <familyName>ExampleLeaderFamily</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">https://orcid.org/0000-0002-5727-2427</nameIdentifier>
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
    $response->assertJsonPath('contributors.0.roles', ['Contact Person']);
    $response->assertJsonPath('contributors.0.orcid', 'https://orcid.org/0000-0001-5727-2427');
    $response->assertJsonPath('contributors.0.firstName', 'ExampleGivenName');
    $response->assertJsonPath('contributors.0.lastName', 'ExampleFamilyName');
    $response->assertJsonPath('contributors.0.affiliations.0.value', 'ExampleAffiliation');
    $response->assertJsonPath('contributors.0.affiliations.0.rorId', 'https://ror.org/04wxnsj81');

    $response->assertJsonPath('contributors.1.type', 'person');
    $response->assertJsonPath('contributors.1.roles', ['Work Package Leader']);
    $response->assertJsonPath('contributors.1.orcid', 'https://orcid.org/0000-0002-5727-2427');
    $response->assertJsonPath('contributors.1.firstName', 'ExampleLeaderGiven');
    $response->assertJsonPath('contributors.1.lastName', 'ExampleLeaderFamily');

    $response->assertJsonPath('contributors.2.type', 'institution');
    $response->assertJsonPath('contributors.2.roles', ['Distributor']);
    $response->assertJsonPath('contributors.2.institutionName', 'ExampleOrganization');
    $response->assertJsonPath('contributors.2.affiliations.0.value', 'ExampleOrganization');
    $response->assertJsonPath('contributors.2.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
});

test('treats research group contributors without a name type as institutions', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <contributors>
    <contributor contributorType="ResearchGroup">
      <contributorName>ExampleContributorRG</contributorName>
      <affiliation affiliationIdentifier="https://ror.org/03yrm5c26" affiliationIdentifierScheme="ROR" schemeURI="https://ror.org">ExampleOrganization</affiliation>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('research-group.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonPath('contributors.0.type', 'institution');
    $response->assertJsonPath('contributors.0.roles', ['Research Group']);
    $response->assertJsonPath('contributors.0.institutionName', 'ExampleContributorRG');
    $response->assertJsonPath('contributors.0.affiliations.0.value', 'ExampleOrganization');
    $response->assertJsonPath('contributors.0.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
});

test('deduplicates contributors that appear multiple times with different roles', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName nameType="Personal">ExampleFamilyNameCP, ExampleGivenNameCP</contributorName>
      <givenName>ExampleGivenNameCP</givenName>
      <familyName>ExampleFamilyNameCP</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">https://orcid.org/0000-0001-5727-2427</nameIdentifier>
      <affiliation>ExampleAffiliation</affiliation>
    </contributor>
    <contributor contributorType="DataCurator">
      <contributorName nameType="Personal">ExampleFamilyNameCP, ExampleGivenNameCP</contributorName>
      <givenName>ExampleGivenNameCP</givenName>
      <familyName>ExampleFamilyNameCP</familyName>
      <affiliation>Additional Affiliation</affiliation>
    </contributor>
    <contributor contributorType="Distributor">
      <contributorName nameType="Organizational">Example Organization</contributorName>
      <nameIdentifier nameIdentifierScheme="ROR" schemeURI="https://ror.org">https://ror.org/03yrm5c26</nameIdentifier>
    </contributor>
    <contributor contributorType="WorkPackageLeader">
      <contributorName nameType="Organizational">Example Organization</contributorName>
      <affiliation>Example Organization Headquarters</affiliation>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('duplicate-contributors.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonCount(2, 'contributors');

    $response->assertJsonPath('contributors.0.type', 'person');
    $response->assertJsonPath('contributors.0.roles', ['Contact Person', 'Data Curator']);
    $response->assertJsonPath('contributors.0.orcid', 'https://orcid.org/0000-0001-5727-2427');
    $response->assertJsonPath('contributors.0.affiliations.0.value', 'ExampleAffiliation');
    $response->assertJsonPath('contributors.0.affiliations.1.value', 'Additional Affiliation');

    $response->assertJsonPath('contributors.1.type', 'institution');
    $response->assertJsonPath('contributors.1.roles', ['Distributor', 'Work Package Leader']);
    $response->assertJsonPath('contributors.1.institutionName', 'Example Organization');
    $response->assertJsonPath('contributors.1.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
    $response->assertJsonPath('contributors.1.affiliations.1.value', 'Example Organization Headquarters');
});

test('deduplicates affiliations regardless of identifier casing', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <contributors>
    <contributor contributorType="Distributor">
      <contributorName nameType="Organizational">Example Organization</contributorName>
      <affiliation affiliationIdentifier="https://ror.org/03YRM5C26" affiliationIdentifierScheme="ROR" schemeURI="https://ror.org">Example Organization</affiliation>
    </contributor>
    <contributor contributorType="Sponsor">
      <contributorName nameType="Organizational">Example Organization</contributorName>
      <affiliation affiliationIdentifier="https://ror.org/03yrm5c26" affiliationIdentifierScheme="ROR" schemeURI="https://ror.org"></affiliation>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('case-dedup-contributors.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonCount(1, 'contributors.0.affiliations');
    $response->assertJsonPath('contributors.0.affiliations.0.value', 'Example Organization');
    $response->assertJsonPath('contributors.0.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
});

test('deduplicates institutions by normalising whitespace in names', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <contributors>
    <contributor contributorType="Distributor">
      <contributorName nameType="Organizational">Example   Organization</contributorName>
    </contributor>
    <contributor contributorType="Sponsor">
      <contributorName nameType="Organizational">Example Organization</contributorName>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('whitespace-dedup-contributors.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonCount(1, 'contributors');
    $response->assertJsonPath('contributors.0.type', 'institution');
    $response->assertJsonPath('contributors.0.institutionName', 'Example   Organization');
});

test('uploading a non-xml file returns validation errors', function () {
    $this->actingAs(User::factory()->create());

    $file = UploadedFile::fake()->create('test.txt', 10, 'text/plain');

    $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertStatus(422)
        ->assertInvalid('file');
});
