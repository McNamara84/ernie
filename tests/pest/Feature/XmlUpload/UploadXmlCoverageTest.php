<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('extracts spatial coverage from geoLocationPoint', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataCount(1, 'coverages');
    $response->assertSessionDataPath('coverages.0.id', 'coverage-1');
    $response->assertSessionDataPath('coverages.0.latMin', '49.282700');
    $response->assertSessionDataPath('coverages.0.lonMin', '-123.120700');
    $response->assertSessionDataPath('coverages.0.latMax', ''); // Point: max coordinates empty
    $response->assertSessionDataPath('coverages.0.lonMax', ''); // Point: max coordinates empty
    $response->assertSessionDataPath('coverages.0.description', 'Vancouver, British Columbia, Canada');
    $response->assertSessionDataPath('coverages.0.timezone', 'UTC');
});

test('extracts spatial coverage from geoLocationBox', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataCount(1, 'coverages');
    $response->assertSessionDataPath('coverages.0.latMin', '49.195000');
    $response->assertSessionDataPath('coverages.0.latMax', '49.315000');
    $response->assertSessionDataPath('coverages.0.lonMin', '-123.270000');
    $response->assertSessionDataPath('coverages.0.lonMax', '-123.020000');
});

test('extracts temporal coverage from date type coverage', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataPath('coverages.0.startDate', '2024-01-01');
    $response->assertSessionDataPath('coverages.0.endDate', '2024-12-31');
});

test('handles multiple geoLocation entries', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataCount(2, 'coverages');
    $response->assertSessionDataPath('coverages.0.id', 'coverage-1');
    $response->assertSessionDataPath('coverages.0.description', 'Location 1');
    $response->assertSessionDataPath('coverages.1.id', 'coverage-2');
    $response->assertSessionDataPath('coverages.1.description', 'Location 2');
});

test('returns empty array when no geoLocations exist', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $response->assertSessionDataPath('coverages', []);
});

test('geoLocationBox takes precedence over geoLocationPoint when both exist', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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
    $response->assertSessionDataPath('coverages.0.latMin', '49.195000');
    $response->assertSessionDataPath('coverages.0.latMax', '49.315000');
    $response->assertSessionDataPath('coverages.0.lonMin', '-123.270000');
    $response->assertSessionDataPath('coverages.0.lonMax', '-123.020000');
});

test('extracts polygon from geoLocationPolygon', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
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
      <geoLocationPlace>Berlin Area</geoLocationPlace>
      <geoLocationPolygon>
        <polygonPoint>
          <pointLatitude>52.5</pointLatitude>
          <pointLongitude>13.4</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>52.6</pointLatitude>
          <pointLongitude>13.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>52.5</pointLatitude>
          <pointLongitude>13.6</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>52.4</pointLatitude>
          <pointLongitude>13.5</pointLongitude>
        </polygonPoint>
      </geoLocationPolygon>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('geolocation-polygon.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataCount(1, 'coverages');
    $response->assertSessionDataPath('coverages.0.type', 'polygon');
    $response->assertSessionDataPath('coverages.0.description', 'Berlin Area');

    // Verify polygon points
    $coverages = $response->sessionData('coverages');
    expect($coverages[0]['polygonPoints'])->toHaveCount(4);
    expect($coverages[0]['polygonPoints'][0])->toBe(['lat' => 52.5, 'lon' => 13.4]);
    expect($coverages[0]['polygonPoints'][1])->toBe(['lat' => 52.6, 'lon' => 13.5]);
    expect($coverages[0]['polygonPoints'][2])->toBe(['lat' => 52.5, 'lon' => 13.6]);
    expect($coverages[0]['polygonPoints'][3])->toBe(['lat' => 52.4, 'lon' => 13.5]);
});

test('geoLocationPolygon takes precedence over point and box', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
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
      <geoLocationPolygon>
        <polygonPoint>
          <pointLatitude>52.5</pointLatitude>
          <pointLongitude>13.4</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>52.6</pointLatitude>
          <pointLongitude>13.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>52.5</pointLatitude>
          <pointLongitude>13.6</pointLongitude>
        </polygonPoint>
      </geoLocationPolygon>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('all-three-types.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Should use polygon, not box or point
    $response->assertSessionDataPath('coverages.0.type', 'polygon');
    $coverages = $response->sessionData('coverages');
    expect($coverages[0]['polygonPoints'])->toHaveCount(3);
});

test('correctly identifies coverage type as point', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('type-point.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.type', 'point');
});

test('correctly identifies coverage type as box', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
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

    $file = UploadedFile::fake()->createWithContent('type-box.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.type', 'box');
});

test('handles polygon with many points', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
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
      <geoLocationPolygon>
        <polygonPoint>
          <pointLatitude>10.5</pointLatitude>
          <pointLongitude>20.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>11.5</pointLatitude>
          <pointLongitude>21.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>12.5</pointLatitude>
          <pointLongitude>22.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>13.5</pointLatitude>
          <pointLongitude>23.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>14.5</pointLatitude>
          <pointLongitude>24.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>15.5</pointLatitude>
          <pointLongitude>25.5</pointLongitude>
        </polygonPoint>
      </geoLocationPolygon>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('polygon-many-points.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.type', 'polygon');
    $coverages = $response->sessionData('coverages');
    expect($coverages[0]['polygonPoints'])->toHaveCount(6);
    expect($coverages[0]['polygonPoints'][5])->toBe(['lat' => 15.5, 'lon' => 25.5]);
});

test('handles multiple geoLocations with mixed types', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://datacite.org/schema/kernel-4"
  xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
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
      <geoLocationPlace>Point Location</geoLocationPlace>
      <geoLocationPoint>
        <pointLatitude>49.2827</pointLatitude>
        <pointLongitude>-123.1207</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
    <geoLocation>
      <geoLocationPlace>Box Location</geoLocationPlace>
      <geoLocationBox>
        <westBoundLongitude>-123.27</westBoundLongitude>
        <eastBoundLongitude>-123.02</eastBoundLongitude>
        <southBoundLatitude>49.195</southBoundLatitude>
        <northBoundLatitude>49.315</northBoundLatitude>
      </geoLocationBox>
    </geoLocation>
    <geoLocation>
      <geoLocationPlace>Polygon Location</geoLocationPlace>
      <geoLocationPolygon>
        <polygonPoint>
          <pointLatitude>52.5</pointLatitude>
          <pointLongitude>13.4</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>52.6</pointLatitude>
          <pointLongitude>13.5</pointLongitude>
        </polygonPoint>
        <polygonPoint>
          <pointLatitude>52.5</pointLatitude>
          <pointLongitude>13.6</pointLongitude>
        </polygonPoint>
      </geoLocationPolygon>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('mixed-coverage-types.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataCount(3, 'coverages');
    $response->assertSessionDataPath('coverages.0.type', 'point');
    $response->assertSessionDataPath('coverages.0.description', 'Point Location');
    $response->assertSessionDataPath('coverages.1.type', 'box');
    $response->assertSessionDataPath('coverages.1.description', 'Box Location');
    $response->assertSessionDataPath('coverages.2.type', 'polygon');
    $response->assertSessionDataPath('coverages.2.description', 'Polygon Location');

    $coverages = $response->sessionData('coverages');
    expect($coverages[2]['polygonPoints'])->toHaveCount(3);
});
