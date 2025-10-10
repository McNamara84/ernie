<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('extracts spatial coverage from geoLocationPoint', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.3/metadata.xsd">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators>
    <creator>
      <creatorName>Test Author</creatorName>
    </creator>
  </creators>
  <titles>
    <title>Test Dataset</title>
  </titles>
  <publisher>Test Publisher</publisher>
  <publicationYear>2024</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <geoLocations>
    <geoLocation>
      <geoLocationPlace>Vancouver, British Columbia, Canada</geoLocationPlace>
      <geoLocationPoint>
        <pointLatitude>49.2827</pointLatitude>
        <pointLongitude>-123.1207</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('geolocation-point.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonCount(1, 'coverages');
    $response->assertJsonPath('coverages.0.id', 'coverage-1');
    $response->assertJsonPath('coverages.0.latMin', '49.282700');
    $response->assertJsonPath('coverages.0.lonMin', '-123.120700');
    $response->assertJsonPath('coverages.0.latMax', ''); // Point: max coordinates empty
    $response->assertJsonPath('coverages.0.lonMax', ''); // Point: max coordinates empty
    $response->assertJsonPath('coverages.0.description', 'Vancouver, British Columbia, Canada');
    $response->assertJsonPath('coverages.0.timezone', 'UTC');
});

test('extracts spatial coverage from geoLocationBox', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.3/metadata.xsd">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators>
    <creator>
      <creatorName>Test Author</creatorName>
    </creator>
  </creators>
  <titles>
    <title>Test Dataset</title>
  </titles>
  <publisher>Test Publisher</publisher>
  <publicationYear>2024</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <geoLocations>
    <geoLocation>
      <geoLocationBox>
        <westBoundLongitude>-123.27</westBoundLongitude>
        <eastBoundLongitude>-123.02</eastBoundLongitude>
        <southBoundLatitude>49.195</southBoundLatitude>
        <northBoundLatitude>49.315</northBoundLatitude>
      </geoLocationBox>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('geolocation-box.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonCount(1, 'coverages');
    $response->assertJsonPath('coverages.0.latMin', '49.195000');
    $response->assertJsonPath('coverages.0.latMax', '49.315000');
    $response->assertJsonPath('coverages.0.lonMin', '-123.270000');
    $response->assertJsonPath('coverages.0.lonMax', '-123.020000');
});

test('extracts temporal coverage from date type coverage', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.3/metadata.xsd">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators>
    <creator>
      <creatorName>Test Author</creatorName>
    </creator>
  </creators>
  <titles>
    <title>Test Dataset</title>
  </titles>
  <publisher>Test Publisher</publisher>
  <publicationYear>2024</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2024-01-01/2024-12-31</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>49.2827</pointLatitude>
        <pointLongitude>-123.1207</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('temporal-coverage.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonPath('coverages.0.startDate', '2024-01-01');
    $response->assertJsonPath('coverages.0.endDate', '2024-12-31');
});

test('handles multiple geoLocation entries', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.3/metadata.xsd">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators>
    <creator>
      <creatorName>Test Author</creatorName>
    </creator>
  </creators>
  <titles>
    <title>Test Dataset</title>
  </titles>
  <publisher>Test Publisher</publisher>
  <publicationYear>2024</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <geoLocations>
    <geoLocation>
      <geoLocationPlace>Location 1</geoLocationPlace>
      <geoLocationPoint>
        <pointLatitude>10.5</pointLatitude>
        <pointLongitude>20.5</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
    <geoLocation>
      <geoLocationPlace>Location 2</geoLocationPlace>
      <geoLocationPoint>
        <pointLatitude>30.5</pointLatitude>
        <pointLongitude>40.5</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('multiple-geolocations.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonCount(2, 'coverages');
    $response->assertJsonPath('coverages.0.id', 'coverage-1');
    $response->assertJsonPath('coverages.0.description', 'Location 1');
    $response->assertJsonPath('coverages.1.id', 'coverage-2');
    $response->assertJsonPath('coverages.1.description', 'Location 2');
});

test('returns empty array when no geoLocations exist', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.3/metadata.xsd">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators>
    <creator>
      <creatorName>Test Author</creatorName>
    </creator>
  </creators>
  <titles>
    <title>Test Dataset</title>
  </titles>
  <publisher>Test Publisher</publisher>
  <publicationYear>2024</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('no-geolocations.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertJsonPath('coverages', []);
});

test('geoLocationBox takes precedence over geoLocationPoint when both exist', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.3/metadata.xsd">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators>
    <creator>
      <creatorName>Test Author</creatorName>
    </creator>
  </creators>
  <titles>
    <title>Test Dataset</title>
  </titles>
  <publisher>Test Publisher</publisher>
  <publicationYear>2024</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>49.2827</pointLatitude>
        <pointLongitude>-123.1207</pointLongitude>
      </geoLocationPoint>
      <geoLocationBox>
        <westBoundLongitude>-123.27</westBoundLongitude>
        <eastBoundLongitude>-123.02</eastBoundLongitude>
        <southBoundLatitude>49.195</southBoundLatitude>
        <northBoundLatitude>49.315</northBoundLatitude>
      </geoLocationBox>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('both-point-and-box.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Should use box coordinates, not point
    $response->assertJsonPath('coverages.0.latMin', '49.195000');
    $response->assertJsonPath('coverages.0.latMax', '49.315000');
    $response->assertJsonPath('coverages.0.lonMin', '-123.270000');
    $response->assertJsonPath('coverages.0.lonMax', '-123.020000');
});
