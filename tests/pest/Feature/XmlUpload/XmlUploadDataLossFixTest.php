<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────────────────
// Fix 1: Title xml:lang attribute is preserved
// ──────────────────────────────────────────────────────────────────

test('extracts xml:lang attribute from titles', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles>
    <title xml:lang="en">English Title</title>
    <title titleType="AlternativeTitle" xml:lang="de">Deutscher Titel</title>
  </titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('title-lang.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('titles.0.language', 'en');
    $response->assertSessionDataPath('titles.1.language', 'de');
    $response->assertSessionDataPath('titles.1.titleType', 'alternative-title');
});

test('title language is null when xml:lang is not present', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles>
    <title>No Language Title</title>
  </titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('title-no-lang.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('titles.0.language', null);
});

// ──────────────────────────────────────────────────────────────────
// Fix 2: Description xml:lang attribute is preserved
// ──────────────────────────────────────────────────────────────────

test('extracts xml:lang attribute from descriptions', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <descriptions>
    <description descriptionType="Abstract" xml:lang="en">English abstract</description>
    <description descriptionType="Methods" xml:lang="de">Deutsche Methoden</description>
  </descriptions>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('desc-lang.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('descriptions.0.language', 'en');
    $response->assertSessionDataPath('descriptions.0.type', 'Abstract');
    $response->assertSessionDataPath('descriptions.1.language', 'de');
    $response->assertSessionDataPath('descriptions.1.type', 'Methods');
});

test('description language is null when xml:lang is not present', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <descriptions>
    <description descriptionType="Abstract">No language abstract</description>
  </descriptions>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('desc-no-lang.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('descriptions.0.language', null);
});

// ──────────────────────────────────────────────────────────────────
// Fix 3: Coverage date with time and timezone is fully extracted
// ──────────────────────────────────────────────────────────────────

test('extracts time and timezone from coverage date range', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-03-31T20:00:00+02:00/2026-03-31T21:00:00+02:00</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>52.3</pointLatitude>
        <pointLongitude>13.0</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-datetime.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.startDate', '2026-03-31');
    $response->assertSessionDataPath('coverages.0.endDate', '2026-03-31');
    $response->assertSessionDataPath('coverages.0.startTime', '20:00');
    $response->assertSessionDataPath('coverages.0.endTime', '21:00');

    // Timezone should be the original offset string from the XML
    $response->assertSessionDataPath('coverages.0.timezone', '+02:00');
});

test('normalizes +00:00 timezone offset to UTC', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-06-15T12:00:00+00:00/2026-06-15T14:00:00+00:00</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>51.5</pointLatitude>
        <pointLongitude>-0.1</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-utc-offset.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.timezone', 'UTC');
});

test('normalizes Z timezone designator to UTC', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-06-15T12:00:00Z/2026-06-15T14:00:00Z</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>51.5</pointLatitude>
        <pointLongitude>-0.1</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-z-timezone.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.timezone', 'UTC');
});

test('preserves seconds in coverage time when present', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-06-15T20:00:30+02:00/2026-06-15T22:15:45+02:00</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>51.5</pointLatitude>
        <pointLongitude>-0.1</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-with-seconds.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.startTime', '20:00:30');
    $response->assertSessionDataPath('coverages.0.endTime', '22:15:45');
});

test('omits seconds when they are zero', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-06-15T14:30:00+02:00</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>51.5</pointLatitude>
        <pointLongitude>-0.1</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-zero-seconds.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.startTime', '14:30');
});

test('datetime without explicit timezone returns empty timezone', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-06-15T20:00:30/2026-06-15T22:15:45</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>51.5</pointLatitude>
        <pointLongitude>-0.1</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-no-tz.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.startDate', '2026-06-15');
    $response->assertSessionDataPath('coverages.0.startTime', '20:00:30');
    $response->assertSessionDataPath('coverages.0.endTime', '22:15:45');
    $response->assertSessionDataPath('coverages.0.timezone', '');
});

test('extracts coverage date without time as date-only', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-04-01/2026-04-16</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>52.3</pointLatitude>
        <pointLongitude>13.0</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-date-only.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.startDate', '2026-04-01');
    $response->assertSessionDataPath('coverages.0.endDate', '2026-04-16');
    $response->assertSessionDataPath('coverages.0.startTime', '');
    $response->assertSessionDataPath('coverages.0.endTime', '');
    $response->assertSessionDataPath('coverages.0.timezone', 'UTC');
});

test('extracts single-date coverage without range separator', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-05-01T14:30:00+05:00</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>52.3</pointLatitude>
        <pointLongitude>13.0</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-single.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataPath('coverages.0.startDate', '2026-05-01');
    $response->assertSessionDataPath('coverages.0.startTime', '14:30');
    $response->assertSessionDataPath('coverages.0.endDate', '');
    $response->assertSessionDataPath('coverages.0.endTime', '');

    $response->assertSessionDataPath('coverages.0.timezone', '+05:00');
});

test('temporal-only coverage without geoLocations preserves time', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Coverage">2026-06-01T08:00:00+02:00/2026-06-01T18:00:00+02:00</date>
  </dates>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('temporal-only.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataCount(1, 'coverages');
    $response->assertSessionDataPath('coverages.0.startDate', '2026-06-01');
    $response->assertSessionDataPath('coverages.0.endDate', '2026-06-01');
    $response->assertSessionDataPath('coverages.0.startTime', '08:00');
    $response->assertSessionDataPath('coverages.0.endTime', '18:00');
    $response->assertSessionDataPath('coverages.0.latMin', '');
    $response->assertSessionDataPath('coverages.0.lonMin', '');
});

test('geoLocation without coverage date has empty time fields', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>52.3</pointLatitude>
        <pointLongitude>13.0</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('geo-no-coverage.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $response->assertSessionDataCount(1, 'coverages');
    $response->assertSessionDataPath('coverages.0.startDate', '');
    $response->assertSessionDataPath('coverages.0.endDate', '');
    $response->assertSessionDataPath('coverages.0.startTime', '');
    $response->assertSessionDataPath('coverages.0.endTime', '');
    $response->assertSessionDataPath('coverages.0.timezone', 'UTC');
});

// ──────────────────────────────────────────────────────────────────
// Fix 4: Coverage dates are removed from the dates array
// ──────────────────────────────────────────────────────────────────

test('coverage dates are filtered from dates array', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <dates>
    <date dateType="Available">2026-04-26</date>
    <date dateType="Coverage">2026-03-31T20:00:00+02:00/2026-03-31T21:00:00+02:00</date>
    <date dateType="Created">2026-04-15</date>
  </dates>
  <geoLocations>
    <geoLocation>
      <geoLocationPoint>
        <pointLatitude>52.3</pointLatitude>
        <pointLongitude>13.0</pointLongitude>
      </geoLocationPoint>
    </geoLocation>
  </geoLocations>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('coverage-filtered.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    // Coverage date should be in coverages, not in dates
    $response->assertSessionDataCount(2, 'dates');
    $response->assertSessionDataPath('dates.0.dateType', 'available');
    $response->assertSessionDataPath('dates.1.dateType', 'created');

    // No date should have dateType "coverage"
    $dates = $response->sessionData('dates');
    $coverageDates = array_filter($dates, fn ($d) => ($d['dateType'] ?? '') === 'coverage');
    expect($coverageDates)->toBeEmpty();

    // Coverage should exist in coverages
    $response->assertSessionDataPath('coverages.0.startDate', '2026-03-31');
    $response->assertSessionDataPath('coverages.0.startTime', '20:00');

    // rawValue key should not be present in dates
    foreach ($dates as $date) {
        expect($date)->not->toHaveKey('rawValue');
    }
});

// ──────────────────────────────────────────────────────────────────
// Fix 5: Chronostratigraphic keyword text uses leaf node
// ──────────────────────────────────────────────────────────────────

test('chronostratigraphic keyword text is leaf node of path', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <subjects>
    <subject subjectScheme="International Chronostratigraphic Chart"
             schemeURI="http://resource.geosciml.org/vocabulary/timescale/gts2020"
             valueURI="http://resource.geosciml.org/classifier/ics/ischart/Jurassic"
             xml:lang="en">Phanerozoic &gt; Mesozoic &gt; Jurassic</subject>
    <subject subjectScheme="International Chronostratigraphic Chart"
             schemeURI="http://resource.geosciml.org/vocabulary/timescale/gts2020"
             valueURI="http://resource.geosciml.org/classifier/ics/ischart/Paleozoic"
             xml:lang="en">Phanerozoic &gt; Paleozoic</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('chronostrat.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $gcmdKeywords = $response->sessionData('gcmdKeywords');
    expect($gcmdKeywords)->toHaveCount(2);

    // Text should be the leaf node, not the full path
    expect($gcmdKeywords[0]['text'])->toBe('Jurassic');
    expect($gcmdKeywords[0]['path'])->toBe('Phanerozoic > Mesozoic > Jurassic');
    expect($gcmdKeywords[0]['scheme'])->toBe('International Chronostratigraphic Chart');
    expect($gcmdKeywords[0]['id'])->toBe('http://resource.geosciml.org/classifier/ics/ischart/Jurassic');

    expect($gcmdKeywords[1]['text'])->toBe('Paleozoic');
    expect($gcmdKeywords[1]['path'])->toBe('Phanerozoic > Paleozoic');
});

test('chronostratigraphic keyword with single-level path uses text as-is', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <identifier identifierType="DOI">10.5072/test</identifier>
  <creators><creator><creatorName>Test</creatorName></creator></creators>
  <titles><title>Test</title></titles>
  <publisher>Test</publisher>
  <publicationYear>2026</publicationYear>
  <resourceType resourceTypeGeneral="Dataset"/>
  <subjects>
    <subject subjectScheme="International Chronostratigraphic Chart"
             schemeURI="http://resource.geosciml.org/vocabulary/timescale/gts2020"
             valueURI="http://resource.geosciml.org/classifier/ics/ischart/Phanerozoic"
             xml:lang="en">Phanerozoic</subject>
  </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('chronostrat-single.xml', $xml);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $gcmdKeywords = $response->sessionData('gcmdKeywords');
    expect($gcmdKeywords)->toHaveCount(1);
    expect($gcmdKeywords[0]['text'])->toBe('Phanerozoic');
    expect($gcmdKeywords[0]['path'])->toBe('Phanerozoic');
});
