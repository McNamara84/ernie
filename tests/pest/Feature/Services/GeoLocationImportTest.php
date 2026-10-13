<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteToResourceTransformer;
use App\Support\GeoLocations\GeoLocationWriteReport;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->seed(['ResourceTypeSeeder', 'TitleTypeSeeder', 'LanguageSeeder', 'PublisherSeeder', 'DateTypeSeeder']);
    $this->user = User::factory()->create();
});

it('deduplicates DataCite locations and returns an operation-specific report', function () {
    $point = ['geoLocationPlace' => 'GFZ', 'geoLocationPoint' => ['pointLatitude' => 52.38, 'pointLongitude' => 13.06]];
    $report = new GeoLocationWriteReport;
    $resource = app(DataCiteToResourceTransformer::class)->transform(['id' => '10.5880/test.geo-import', 'attributes' => [
        'doi' => '10.5880/test.geo-import', 'titles' => [['title' => 'Import locations']],
        'publicationYear' => 2026, 'types' => ['resourceTypeGeneral' => 'Dataset'],
        'geoLocations' => [$point, $point, [...$point, 'geoLocationPlace' => 'Different description']],
    ]], $this->user->id, $report);
    expect($resource->geoLocations()->count())->toBe(2)->and($report->removedCount)->toBe(1);

    $otherReport = new GeoLocationWriteReport;
    $other = app(DataCiteToResourceTransformer::class)->transform(['id' => '10.5880/test.geo-import2', 'attributes' => [
        'doi' => '10.5880/test.geo-import2', 'titles' => [['title' => 'Another import']],
        'publicationYear' => 2026, 'types' => ['resourceTypeGeneral' => 'Dataset'], 'geoLocations' => [$point],
    ]], $this->user->id, $otherReport);
    expect($otherReport->removedCount)->toBe(0)->and($other->geoLocations()->count())->toBe(1);
});

it('reports XML upload deduplication in the response while retaining different descriptions', function () {
    $point = '<geoLocation><geoLocationPlace>GFZ</geoLocationPlace><geoLocationPoint><pointLatitude>52.38</pointLatitude><pointLongitude>13.06</pointLongitude></geoLocationPoint></geoLocation>';
    $xml = '<?xml version="1.0"?><resource xmlns="http://datacite.org/schema/kernel-4"><identifier identifierType="DOI">10.5880/test.xml-geos</identifier><creators><creator><creatorName>Test Author</creatorName></creator></creators><titles><title>XML locations</title></titles><publisher>GFZ</publisher><publicationYear>2026</publicationYear><resourceType resourceTypeGeneral="Dataset"/><geoLocations>'
        .$point.$point.str_replace('>GFZ<', '>Different description<', $point).'</geoLocations></resource>';
    $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
        'file' => UploadedFile::fake()->createWithContent('locations.xml', $xml),
    ])->assertSuccessful()->assertJsonPath('deduplicatedGeoLocations', 1);
    $resource = Resource::findOrFail($response->json('resourceId'));
    expect($resource->geoLocations()->count())->toBe(2);
});
