<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->seed(['ResourceTypeSeeder', 'TitleTypeSeeder', 'LanguageSeeder', 'PublisherSeeder', 'DateTypeSeeder']);
    $this->user = User::factory()->create();
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
