<?php

use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('returns resource type id from uploaded XML', function () {
    $this->actingAs(User::factory()->create());

    $type = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
        'active' => true,
        'elmo_active' => true,
    ]);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('resourceType', (string) $type->id);
});

test('extracts contributors from uploaded XML', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <contributors>
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

    $response->assertSessionDataPath('contributors.0.type', 'person');
    $response->assertSessionDataPath('contributors.0.roles', ['Work Package Leader']);
    $response->assertSessionDataPath('contributors.0.orcid', '0000-0002-5727-2427');
    $response->assertSessionDataPath('contributors.0.firstName', 'ExampleLeaderGiven');
    $response->assertSessionDataPath('contributors.0.lastName', 'ExampleLeaderFamily');

    $response->assertSessionDataPath('contributors.1.type', 'institution');
    $response->assertSessionDataPath('contributors.1.roles', ['Distributor']);
    $response->assertSessionDataPath('contributors.1.institutionName', 'ExampleOrganization');
    // Affiliation can be institution name or ROR-resolved name
    expect($response->sessionData('contributors.1.affiliations.0.value'))
        ->toBeIn(['ExampleOrganization', 'California Digital Library']);
    $response->assertSessionDataPath('contributors.1.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
});

test('treats research group contributors without a name type as institutions', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataPath('contributors.0.type', 'institution');
    $response->assertSessionDataPath('contributors.0.roles', ['Research Group']);
    $response->assertSessionDataPath('contributors.0.institutionName', 'ExampleContributorRG');
    // Affiliation value can be either XML text or ROR-resolved name
    expect($response->sessionData('contributors.0.affiliations.0.value'))
        ->toBeIn(['ExampleOrganization', 'California Digital Library']);
    $response->assertSessionDataPath('contributors.0.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
});

test('deduplicates contributors that appear multiple times with different roles', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <contributors>
    <contributor contributorType="DataCurator">
      <contributorName nameType="Personal">ExampleFamilyNameCP, ExampleGivenNameCP</contributorName>
      <givenName>ExampleGivenNameCP</givenName>
      <familyName>ExampleFamilyNameCP</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">https://orcid.org/0000-0001-5727-2427</nameIdentifier>
      <affiliation>ExampleAffiliation</affiliation>
    </contributor>
    <contributor contributorType="DataManager">
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

    $response->assertSessionDataCount(2, 'contributors');

    $response->assertSessionDataPath('contributors.0.type', 'person');
    $response->assertSessionDataPath('contributors.0.roles', ['Data Curator', 'Data Manager']);
    $response->assertSessionDataPath('contributors.0.orcid', '0000-0001-5727-2427');
    $response->assertSessionDataPath('contributors.0.affiliations.0.value', 'ExampleAffiliation');
    $response->assertSessionDataPath('contributors.0.affiliations.1.value', 'Additional Affiliation');

    $response->assertSessionDataPath('contributors.1.type', 'institution');
    $response->assertSessionDataPath('contributors.1.roles', ['Distributor', 'Work Package Leader']);
    $response->assertSessionDataPath('contributors.1.institutionName', 'Example Organization');
    $response->assertSessionDataPath('contributors.1.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
    $response->assertSessionDataPath('contributors.1.affiliations.1.value', 'Example Organization Headquarters');
});

test('deduplicates affiliations regardless of identifier casing', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataCount(1, 'contributors.0.affiliations');
    // Affiliation value can be either XML text or ROR-resolved name
    expect($response->sessionData('contributors.0.affiliations.0.value'))
        ->toBeIn(['Example Organization', 'California Digital Library']);
    $response->assertSessionDataPath('contributors.0.affiliations.0.rorId', 'https://ror.org/03yrm5c26');
});

test('deduplicates institutions by normalising whitespace in names', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataCount(1, 'contributors');
    $response->assertSessionDataPath('contributors.0.type', 'institution');
    $response->assertSessionDataPath('contributors.0.institutionName', 'Example   Organization');
});

test('uploading a non-xml file returns validation errors', function () {
    $this->actingAs(User::factory()->create());

    $file = UploadedFile::fake()->create('test.txt', 10, 'text/plain');

    $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertStatus(422)
        ->assertInvalid('file');
});

test('extracts descriptions from uploaded XML', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description xml:lang="en" descriptionType="Abstract">This is an example abstract describing the dataset.</description>
    <description xml:lang="en" descriptionType="Methods">These are the methods used to collect the data.</description>
    <description xml:lang="en" descriptionType="TechnicalInfo">Technical information about the dataset.</description>
    <description xml:lang="en" descriptionType="TableOfContents">Table of contents for the dataset.</description>
    <description xml:lang="en" descriptionType="SeriesInformation">Series information.</description>
    <description xml:lang="en" descriptionType="Other">Other relevant information.</description>
  </descriptions>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('descriptions.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('descriptions.0.type', 'Abstract');
    $response->assertSessionDataPath('descriptions.0.description', 'This is an example abstract describing the dataset.');

    $response->assertSessionDataPath('descriptions.1.type', 'Methods');
    $response->assertSessionDataPath('descriptions.1.description', 'These are the methods used to collect the data.');

    $response->assertSessionDataPath('descriptions.2.type', 'TechnicalInfo');
    $response->assertSessionDataPath('descriptions.2.description', 'Technical information about the dataset.');

    $response->assertSessionDataPath('descriptions.3.type', 'TableOfContents');
    $response->assertSessionDataPath('descriptions.3.description', 'Table of contents for the dataset.');

    $response->assertSessionDataPath('descriptions.4.type', 'SeriesInformation');
    $response->assertSessionDataPath('descriptions.4.description', 'Series information.');

    $response->assertSessionDataPath('descriptions.5.type', 'Other');
    $response->assertSessionDataPath('descriptions.5.description', 'Other relevant information.');
});

test('handles XML without descriptions gracefully', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <titles>
    <title>Example Title</title>
  </titles>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('no-descriptions.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('descriptions', []);
});

test('filters out empty descriptions from XML', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description xml:lang="en" descriptionType="Abstract">Valid abstract</description>
    <description xml:lang="en" descriptionType="Methods"></description>
    <description xml:lang="en" descriptionType="TechnicalInfo">   </description>
    <description xml:lang="en" descriptionType="Other">Another valid description</description>
  </descriptions>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('empty-descriptions.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $data = $response->sessionData();
    expect($data['descriptions'])->toHaveCount(2);
    expect($data['descriptions'][0]['type'])->toBe('Abstract');
    expect($data['descriptions'][0]['description'])->toBe('Valid abstract');
    expect($data['descriptions'][1]['type'])->toBe('Other');
    expect($data['descriptions'][1]['description'])->toBe('Another valid description');
});

test('extracts dates from uploaded XML', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <dates>
    <date dateType="Accepted">2024-01-15</date>
    <date dateType="Collected">2024-01-01/2024-12-31</date>
    <date dateType="Available">/2025-01-01</date>
    <date dateType="Created">2023-05-20</date>
    <date dateType="Other">2024-06-15</date>
  </dates>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('dates.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Test single date
    $response->assertSessionDataPath('dates.0.dateType', 'accepted');
    $response->assertSessionDataPath('dates.0.startDate', '2024-01-15');
    $response->assertSessionDataPath('dates.0.endDate', '');

    // Test date range
    $response->assertSessionDataPath('dates.1.dateType', 'collected');
    $response->assertSessionDataPath('dates.1.startDate', '2024-01-01');
    $response->assertSessionDataPath('dates.1.endDate', '2024-12-31');

    // Test open range (only endDate)
    $response->assertSessionDataPath('dates.2.dateType', 'available');
    $response->assertSessionDataPath('dates.2.startDate', '');
    $response->assertSessionDataPath('dates.2.endDate', '2025-01-01');

    // Test additional dates
    $response->assertSessionDataPath('dates.3.dateType', 'created');
    $response->assertSessionDataPath('dates.3.startDate', '2023-05-20');

    $response->assertSessionDataPath('dates.4.dateType', 'other');
    $response->assertSessionDataPath('dates.4.startDate', '2024-06-15');
});

test('handles XML without dates gracefully', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <titles>
    <title>Example Title</title>
  </titles>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('no-dates.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('dates', []);
});

test('filters out empty dates from XML', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <dates>
    <date dateType="Accepted">2024-01-15</date>
    <date dateType="Created"></date>
    <date dateType="Issued">   </date>
    <date dateType="Collected">2024-06-01/2024-06-30</date>
  </dates>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('empty-dates.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $data = $response->sessionData();
    expect($data['dates'])->toHaveCount(2);
    expect($data['dates'][0]['dateType'])->toBe('accepted');
    expect($data['dates'][0]['startDate'])->toBe('2024-01-15');
    expect($data['dates'][1]['dateType'])->toBe('collected');
    expect($data['dates'][1]['startDate'])->toBe('2024-06-01');
    expect($data['dates'][1]['endDate'])->toBe('2024-06-30');
});

test('converts date types to kebab-case', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <dates>
    <date dateType="Accepted">2024-01-15</date>
    <date dateType="Available">2024-02-01</date>
    <date dateType="Copyrighted">2024-03-01</date>
  </dates>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('date-types.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('dates.0.dateType', 'accepted');
    $response->assertSessionDataPath('dates.1.dateType', 'available');
    $response->assertSessionDataPath('dates.2.dateType', 'copyrighted');
});

test('extracts GCMD keywords from all three thesauri', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject>Free keyword</subject>
    <subject subjectScheme="NASA/GCMD Earth Science Keywords" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/4e366444-01ea-4517-9d93-56f55ddf41b7">BIODIVERSITY FUNCTIONS</subject>
    <subject subjectScheme="NASA/GCMD Earth Platforms Keywords" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/812304fb-2eaf-4ce8-ac49-2de68c025927">Rockets</subject>
    <subject subjectScheme="NASA/GCMD Instruments" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/d8480746-ff39-4de8-ba2e-b5de47890c78">ICE AUGERS</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('gcmd-keywords.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Should extract all three GCMD thesauri keywords
    $response->assertSessionDataCount(3, 'gcmdKeywords');

    // Science Keywords
    $response->assertSessionDataPath('gcmdKeywords.0.scheme', 'Science Keywords');
    $response->assertSessionDataPath('gcmdKeywords.0.uuid', '4e366444-01ea-4517-9d93-56f55ddf41b7');
    $response->assertSessionDataPath('gcmdKeywords.0.text', 'BIODIVERSITY FUNCTIONS');

    // Platforms
    $response->assertSessionDataPath('gcmdKeywords.1.scheme', 'Platforms');
    $response->assertSessionDataPath('gcmdKeywords.1.uuid', '812304fb-2eaf-4ce8-ac49-2de68c025927');
    $response->assertSessionDataPath('gcmdKeywords.1.text', 'Rockets');

    // Instruments
    $response->assertSessionDataPath('gcmdKeywords.2.scheme', 'Instruments');
    $response->assertSessionDataPath('gcmdKeywords.2.uuid', 'd8480746-ff39-4de8-ba2e-b5de47890c78');
    $response->assertSessionDataPath('gcmdKeywords.2.text', 'ICE AUGERS');
});

test('recognizes GCMD keywords with legacy ELMO subjectScheme names', function () {
    $this->actingAs(User::factory()->create());

    // Test all supported legacy format variations:
    // - "NASA/GCMD Platforms Keywords" (without "Earth")
    // - "GCMD Platforms" (short format)
    // - "GCMD Instruments" (short format)
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="NASA/GCMD Platforms Keywords" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/812304fb-2eaf-4ce8-ac49-2de68c025927">Rockets</subject>
    <subject subjectScheme="GCMD Platforms" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/9e9e86b0-d613-4069-abe2-8291a6fac3ef">Titan 34D</subject>
    <subject subjectScheme="GCMD Instruments" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/d8480746-ff39-4de8-ba2e-b5de47890c78">ICE AUGERS</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('legacy-formats.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // All three legacy formats should be recognized
    $response->assertSessionDataCount(3, 'gcmdKeywords');

    // "NASA/GCMD Platforms Keywords" (without "Earth")
    $response->assertSessionDataPath('gcmdKeywords.0.scheme', 'Platforms');
    $response->assertSessionDataPath('gcmdKeywords.0.uuid', '812304fb-2eaf-4ce8-ac49-2de68c025927');

    // "GCMD Platforms" (short format)
    $response->assertSessionDataPath('gcmdKeywords.1.scheme', 'Platforms');
    $response->assertSessionDataPath('gcmdKeywords.1.uuid', '9e9e86b0-d613-4069-abe2-8291a6fac3ef');

    // "GCMD Instruments" (short format)
    $response->assertSessionDataPath('gcmdKeywords.2.scheme', 'Instruments');
    $response->assertSessionDataPath('gcmdKeywords.2.uuid', 'd8480746-ff39-4de8-ba2e-b5de47890c78');
});

// =============================================================================
// Contact Person Handling Tests (Issue #404)
// =============================================================================

test('marks author as contact person when ContactPerson contributor matches by ORCID', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Doe, Jane</creatorName>
      <givenName>Jane</givenName>
      <familyName>Doe</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org/">0000-0001-2345-6789</nameIdentifier>
    </creator>
  </creators>
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName>Doe, Jane</contributorName>
      <givenName>Jane</givenName>
      <familyName>Doe</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org/">0000-0001-2345-6789</nameIdentifier>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('contact-by-orcid.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Author should be marked as contact person
    $response->assertSessionDataPath('authors.0.isContact', true);
    $response->assertSessionDataPath('authors.0.firstName', 'Jane');
    $response->assertSessionDataPath('authors.0.lastName', 'Doe');

    // ContactPerson should NOT be in contributors
    $response->assertSessionDataCount(0, 'contributors');
});

test('marks author as contact person when ContactPerson contributor matches by name', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Smith, John</creatorName>
      <givenName>John</givenName>
      <familyName>Smith</familyName>
    </creator>
  </creators>
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName>Smith, John</contributorName>
      <givenName>John</givenName>
      <familyName>Smith</familyName>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('contact-by-name.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Author should be marked as contact person
    $response->assertSessionDataPath('authors.0.isContact', true);

    // ContactPerson should NOT be in contributors
    $response->assertSessionDataCount(0, 'contributors');
});

test('adds ContactPerson as new author when not matching existing creator', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Doe, Jane</creatorName>
      <givenName>Jane</givenName>
      <familyName>Doe</familyName>
    </creator>
  </creators>
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName>Different, Person</contributorName>
      <givenName>Person</givenName>
      <familyName>Different</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org/">0000-0009-8765-4321</nameIdentifier>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('contact-new-author.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Two authors: original creator and new contact person
    $response->assertSessionDataCount(2, 'authors');

    // First author (original creator) should NOT be contact person
    $response->assertSessionDataPath('authors.0.firstName', 'Jane');
    $response->assertSessionDataPath('authors.0.lastName', 'Doe');
    expect($response->sessionData('authors.0.isContact'))->toBeFalsy();

    // Second author (added from ContactPerson) should be contact person
    $response->assertSessionDataPath('authors.1.firstName', 'Person');
    $response->assertSessionDataPath('authors.1.lastName', 'Different');
    $response->assertSessionDataPath('authors.1.isContact', true);
    $response->assertSessionDataPath('authors.1.orcid', '0000-0009-8765-4321');

    // ContactPerson should NOT be in contributors
    $response->assertSessionDataCount(0, 'contributors');
});

test('extracts email and website from ISO pointOfContact for contact person', function () {
    $this->actingAs(User::factory()->create());

    $xmlPath = base_path('tests/pest/dataset-examples/contact-person-with-iso.xml');
    $xmlContent = file_get_contents($xmlPath);

    $file = UploadedFile::fake()->createWithContent('contact-with-iso.xml', $xmlContent);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Author should be marked as contact person with email and website from ISO
    $response->assertSessionDataPath('authors.0.isContact', true);
    $response->assertSessionDataPath('authors.0.firstName', 'Max');
    $response->assertSessionDataPath('authors.0.lastName', 'Mustermann');
    $response->assertSessionDataPath('authors.0.email', 'max.mustermann@example.org');
    $response->assertSessionDataPath('authors.0.website', 'https://example.org/mustermann');

    // ContactPerson should NOT be in contributors
    $response->assertSessionDataCount(0, 'contributors');
});

test('handles XML without ISO part gracefully', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Doe, Jane</creatorName>
      <givenName>Jane</givenName>
      <familyName>Doe</familyName>
    </creator>
  </creators>
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName>Doe, Jane</contributorName>
      <givenName>Jane</givenName>
      <familyName>Doe</familyName>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('contact-no-iso.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Author should be marked as contact person but without email/website
    $response->assertSessionDataPath('authors.0.isContact', true);
    $response->assertSessionDataPath('authors.0.email', '');
    $response->assertSessionDataPath('authors.0.website', '');
});

test('matches correct email to correct person with multiple contact persons', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<envelope>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Mueller, Max</creatorName>
      <givenName>Max</givenName>
      <familyName>Mueller</familyName>
    </creator>
    <creator>
      <creatorName nameType="Personal">Schmidt, Anna</creatorName>
      <givenName>Anna</givenName>
      <familyName>Schmidt</familyName>
    </creator>
  </creators>
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName>Mueller, Max</contributorName>
      <givenName>Max</givenName>
      <familyName>Mueller</familyName>
    </contributor>
    <contributor contributorType="ContactPerson">
      <contributorName>Schmidt, Anna</contributorName>
      <givenName>Anna</givenName>
      <familyName>Schmidt</familyName>
    </contributor>
  </contributors>
</resource>
<MD_Metadata xmlns="http://www.isotc211.org/2005/gmd" xmlns:gco="http://www.isotc211.org/2005/gco">
  <identificationInfo>
    <MD_DataIdentification>
      <pointOfContact>
        <CI_ResponsibleParty>
          <individualName>
            <gco:CharacterString>Mueller, Max</gco:CharacterString>
          </individualName>
          <contactInfo>
            <CI_Contact>
              <address>
                <CI_Address>
                  <electronicMailAddress>
                    <gco:CharacterString>max@example.com</gco:CharacterString>
                  </electronicMailAddress>
                </CI_Address>
              </address>
              <onlineResource>
                <CI_OnlineResource>
                  <linkage>
                    <URL>https://max.example.com</URL>
                  </linkage>
                </CI_OnlineResource>
              </onlineResource>
            </CI_Contact>
          </contactInfo>
        </CI_ResponsibleParty>
      </pointOfContact>
      <pointOfContact>
        <CI_ResponsibleParty>
          <individualName>
            <gco:CharacterString>Schmidt, Anna</gco:CharacterString>
          </individualName>
          <contactInfo>
            <CI_Contact>
              <address>
                <CI_Address>
                  <electronicMailAddress>
                    <gco:CharacterString>anna@example.com</gco:CharacterString>
                  </electronicMailAddress>
                </CI_Address>
              </address>
              <onlineResource>
                <CI_OnlineResource>
                  <linkage>
                    <URL>https://anna.example.com</URL>
                  </linkage>
                </CI_OnlineResource>
              </onlineResource>
            </CI_Contact>
          </contactInfo>
        </CI_ResponsibleParty>
      </pointOfContact>
    </MD_DataIdentification>
  </identificationInfo>
</MD_Metadata>
</envelope>
XML;

    $file = UploadedFile::fake()->createWithContent('multiple-contacts.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Both authors should be contact persons with their respective emails
    $response->assertSessionDataPath('authors.0.isContact', true);
    $response->assertSessionDataPath('authors.0.firstName', 'Max');
    $response->assertSessionDataPath('authors.0.lastName', 'Mueller');
    $response->assertSessionDataPath('authors.0.email', 'max@example.com');
    $response->assertSessionDataPath('authors.0.website', 'https://max.example.com');

    $response->assertSessionDataPath('authors.1.isContact', true);
    $response->assertSessionDataPath('authors.1.firstName', 'Anna');
    $response->assertSessionDataPath('authors.1.lastName', 'Schmidt');
    $response->assertSessionDataPath('authors.1.email', 'anna@example.com');
    $response->assertSessionDataPath('authors.1.website', 'https://anna.example.com');
});

test('excludes ContactPerson from contributors array', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <creators>
    <creator>
      <creatorName nameType="Personal">Doe, Jane</creatorName>
      <givenName>Jane</givenName>
      <familyName>Doe</familyName>
    </creator>
  </creators>
  <contributors>
    <contributor contributorType="ContactPerson">
      <contributorName>Doe, Jane</contributorName>
      <givenName>Jane</givenName>
      <familyName>Doe</familyName>
    </contributor>
    <contributor contributorType="DataCurator">
      <contributorName nameType="Personal">Other, Person</contributorName>
      <givenName>Person</givenName>
      <familyName>Other</familyName>
    </contributor>
  </contributors>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('mixed-contributors.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Only the non-ContactPerson contributor should be in contributors
    $response->assertSessionDataCount(1, 'contributors');
    $response->assertSessionDataPath('contributors.0.firstName', 'Person');
    $response->assertSessionDataPath('contributors.0.lastName', 'Other');
    $response->assertSessionDataPath('contributors.0.roles', ['Data Curator']);

    // ContactPerson should be merged into author
    $response->assertSessionDataPath('authors.0.isContact', true);
});
