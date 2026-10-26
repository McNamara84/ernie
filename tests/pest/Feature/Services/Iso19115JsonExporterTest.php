<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Person;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Iso19115\Iso19115JsonExporter;
use App\Services\Iso19115\Iso19115JsonValidator;
use App\Services\Iso19115\Iso19115ResourceProfileService;

covers(Iso19115JsonExporter::class, Iso19115ResourceProfileService::class);

function isoJsonResource(array $attributes = []): Resource
{
    $resource = Resource::factory()->create(['doi' => '10.5880/json/test', 'publication_year' => 2026, ...$attributes]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Seismic observations']);
    Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => '<p>Earth &amp; water: ÄÖÜ</p>']);

    return $resource->refresh();
}

function validatedIsoJson(Resource $resource): array
{
    $json = app(Iso19115JsonExporter::class)->export($resource->refresh());
    expect(app(Iso19115JsonValidator::class)->validate($json)->errors)->toBe([]);

    return $json;
}

test('exports deterministic schema-valid resource metadata without geography', function () {
    $resource = isoJsonResource();
    $json = validatedIsoJson($resource);
    expect($json['type'])->toBe('Feature')->and($json['id'])->toBe($resource->doi)
        ->and($json['geometry'])->toBeNull()
        ->and($json['conformsTo'])->toBe([config('iso19115.json.schema')])
        ->and($json['properties']['identificationInfo']['abstract'])->toBe('Earth & water: ÄÖÜ')
        ->and($json['properties']['identificationInfo']['citation']['date'])->toBe(['publication' => '2026'])
        ->and($json['properties']['defaultLocale'])->toBe(['language' => 'eng', 'characterEncoding' => 'utf8']);
    $this->travel(1)->day();
    expect(validatedIsoJson($resource))->toBe($json);
});

test('supports every existing ISO resource type except physical objects', function (string $slug) {
    $type = ResourceType::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_active' => true]);
    $resource = isoJsonResource(['resource_type_id' => $type->id]);
    expect(app(Iso19115ResourceProfileService::class)->supportsJson($resource))->toBeTrue();
    validatedIsoJson($resource);
})->with(['dataset', 'collection', 'model', 'instrument', 'service', 'software', 'computational-notebook', 'workflow', 'interactive-resource', 'image']);

test('rejects unsupported resources and disabled JSON without changing XML availability', function (string $slug, bool $enabled, bool $xml) {
    $type = ResourceType::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_active' => true]);
    $resource = isoJsonResource(['resource_type_id' => $type->id]);
    config(['iso19115.json.enabled' => $enabled]);
    expect(app(Iso19115ResourceProfileService::class)->supports($resource))->toBe($xml)
        ->and(app(Iso19115ResourceProfileService::class)->supportsJson($resource))->toBeFalse();
    expect(fn () => app(Iso19115JsonExporter::class)->export($resource))->toThrow(RuntimeException::class);
})->with([['physical-object', true, true], ['project', true, false], ['dataset', false, true]]);

test('handles missing legacy strings without synthetic metadata', function () {
    $resource = Resource::factory()->create(['language_id' => null, 'version' => null, 'publisher_id' => null]);
    $json = validatedIsoJson($resource);
    expect($json['properties']['identificationInfo']['citation']['title'])->toBe('')
        ->and($json['properties']['identificationInfo']['abstract'])->toBe('')
        ->and($json['properties'])->not->toHaveKeys(['defaultLocale', 'dataQualityInfo']);
});

test('maps snapshot names affiliations identifiers and public websites without disclosing contact emails', function () {
    $resource = isoJsonResource();
    $person = Person::factory()->create(['given_name' => 'Current', 'family_name' => 'Name', 'name_identifier' => 'https://orcid.org/0000-0002-1825-0097', 'name_identifier_scheme' => 'ORCID']);
    $creator = ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $resource->id, 'name_snapshot' => 'Original, Name', 'is_contact' => true, 'email' => 'private@example.com', 'website' => 'https://example.org/person']);
    $creator->affiliations()->create(['name' => 'GFZ', 'identifier' => 'https://ror.org/04z8jg394', 'identifier_scheme' => 'ROR']);
    ResourceCreator::factory()->forInstitution()->create(['resource_id' => $resource->id, 'position' => 2]);
    $json = validatedIsoJson($resource);
    $identification = $json['properties']['identificationInfo'];
    $party = $identification['citation']['citedResponsibleParty'][0]['party'][0];
    expect($party['type'])->toBe('CI_Organisation')->and($party['name'])->toBe('GFZ')
        ->and($party['individual'][0]['name'])->toBe('Original, Name')
        ->and($party['individual'][0]['partyIdentifier'][0]['codeSpace'])->toBe('ORCID')
        ->and($identification['pointOfContact'][0]['role'])->toBe('pointOfContact')
        ->and(json_encode($json))->not->toContain('private@example.com');
});

test('keeps precise dates and omits intervals and invalid calendar dates', function () {
    $resource = isoJsonResource();
    foreach ([['Issued', '2026-10-26'], ['Created', '2025-04'], ['Created', '2025-05'], ['Updated', '2026-02-30'], ['Collected', '2024/2025']] as [$slug, $value]) {
        $type = DateType::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $value]);
    }
    $json = validatedIsoJson($resource);
    expect($json['properties']['identificationInfo']['citation']['date'])->toBe(['publication' => '2026-10-26', 'creation' => '2025-04'])
        ->and(json_encode($json))->not->toContain('2024/2025');
});

test('exports multiple geometries and ISO extents without temporal extensions', function () {
    $resource = isoJsonResource();
    GeoLocation::factory()->withPoint(13, 52)->create(['resource_id' => $resource->id, 'place' => 'Potsdam', 'start_date' => '2020-01-01']);
    GeoLocation::factory()->withBox(170, -170, -10, 10)->create(['resource_id' => $resource->id, 'place' => null]);
    $json = validatedIsoJson($resource);
    expect($json['geometry']['type'])->toBe('GeometryCollection')
        ->and($json['geometry']['geometries'][1]['type'])->toBe('MultiPolygon')
        ->and($json['properties']['identificationInfo']['extent'][0]['geographicElement'][0]['geographicIdentifier']['code'])->toBe('Potsdam')
        ->and($json['properties']['identificationInfo']['extent'][1]['geographicElement'][0]['eastBoundLongitude'])->toBe(-170.0)
        ->and(json_encode($json))->not->toContain('temporalElement');
});

test('exposes only available downloads with sizes and omits suppressed URLs', function (string $state) {
    $resource = isoJsonResource(['access_level' => $state === 'embargo' ? AccessLevel::EMBARGOED : null]);
    $format = $resource->formats()->create(['value' => 'application/zip']);
    $size = $resource->sizes()->create(['numeric_value' => 1.5, 'unit' => 'MB']);
    $landing = LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi, 'ftp_url' => $state === 'request' ? null : 'https://example.org/data.zip', 'ftp_format_id' => $format->id, 'ftp_size_id' => $size->id, 'downloads_unavailable' => $state === 'suppressed', 'is_tombstone' => $state === 'tombstone']);
    $json = validatedIsoJson($resource);
    $encoded = json_encode($json);
    if ($state === 'available') {
        expect($encoded)->toContain('data.zip')
            ->and($json['properties']['distributionInfo'][0]['transferOptions'][1]['transferSize'])->toBe(1.5);
    } else {
        expect($encoded)->not->toContain('data.zip');
    }
    expect($json['properties']['metadataLinkage'][0]['linkage'])->toBe(url($landing->getPublicPath().'/metadata/iso-19115-4.json'));
})->with(['available', 'request', 'suppressed', 'embargo', 'tombstone']);

test('requires a stored metadata date and configured contact', function () {
    $resource = isoJsonResource();
    $resource->created_at = null;
    $resource->updated_at = null;
    expect(fn () => app(Iso19115JsonExporter::class)->export($resource))->toThrow(RuntimeException::class, 'persisted metadata date');
    $resource->refresh();
    config(['iso19115.metadata_contact.name' => '']);
    expect(fn () => app(Iso19115JsonExporter::class)->export($resource))->toThrow(RuntimeException::class, 'metadata contact');
});

test('maps contributors funding keywords licenses and documentation without extension fields', function () {
    $resource = isoJsonResource();
    $contributor = ResourceContributor::factory()->create(['resource_id' => $resource->id]);
    $roles = collect(['Editor', 'ContactPerson'])->map(fn (string $slug) => ContributorType::firstOrCreate(['slug' => $slug], ['name' => $slug])->id);
    $contributor->contributorTypes()->sync($roles);
    $resource->fundingReferences()->create(['funder_name' => 'Research Foundation', 'funder_identifier' => 'https://doi.org/10.13039/501100001659', 'award_number' => '42', 'award_title' => 'Earth project', 'award_uri' => 'https://example.org/award/42']);
    $resource->alternateIdentifiers()->create(['value' => 'local-42', 'type' => 'Local']);
    $resource->subjects()->create(['value' => 'Geology', 'subject_scheme' => 'Vocabulary', 'scheme_uri' => 'https://example.org/vocabulary', 'value_uri' => 'https://example.org/geology']);
    $resource->subjects()->create(['value' => 'Free keyword']);
    $resource->resourceRights()->create(['rights_text' => 'Open licence', 'rights_uri' => 'https://example.org/licence']);
    Description::factory()->methods()->create(['resource_id' => $resource->id, 'value' => '<p>Calibrated<br>measurements</p>']);
    $identifierType = IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI']);
    foreach (['IsDocumentedBy' => 'documentation', 'IsDerivedFrom' => 'source', 'IsSupplementTo' => 'omitted'] as $slug => $suffix) {
        $relationType = RelationType::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $resource->relatedIdentifiers()->create(['identifier' => '10.5880/'.$suffix, 'identifier_type_id' => $identifierType->id, 'relation_type_id' => $relationType->id]);
    }
    $json = validatedIsoJson($resource);
    $identification = $json['properties']['identificationInfo'];
    expect(array_column($identification['citation']['citedResponsibleParty'], 'role'))->toContain('editor', 'funder', 'publisher')
        ->and($identification['pointOfContact'][0]['role'])->toBe('pointOfContact')
        ->and($identification['credit'][0])->toContain('42', 'Earth project', 'https://example.org/award/42')
        ->and($identification['citation']['identifier'][1])->toBe(['code' => 'local-42', 'codeSpace' => 'Local'])
        ->and($identification['descriptiveKeywords'][0]['thesaurusName']['title'])->toBe('Vocabulary')
        ->and($identification['resourceConstraints'][0]['reference'][0]['onlineResource'][0]['linkage'])->toBe('https://example.org/licence')
        ->and($identification['supplementalInformation'])->toContain("Calibrated\nmeasurements")
        ->and($identification['additionalDocumentation'][0]['identifier'][0]['code'])->toBe('10.5880/documentation')
        ->and($json['properties']['resourceLineage'][0]['source'][0]['sourceCitation']['identifier'][0]['code'])->toBe('10.5880/source')
        ->and(json_encode($json))->not->toContain('10.5880/omitted', 'value_uri', 'temporalElement');
});

test('retains usable downloads without MIME types and keeps repositories distinct', function () {
    $resource = isoJsonResource();
    $landing = LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi, 'ftp_url' => 'https://example.org/fallback']);
    $landing->files()->create(['url' => 'https://example.org/file', 'position' => 0]);
    $landing->links()->create(['url' => 'https://example.org/extra', 'label' => 'Extra', 'kind' => LandingPageLink::KIND_DOWNLOAD, 'position' => 0]);
    $landing->links()->create(['url' => 'https://example.org/repository', 'label' => 'Repository', 'kind' => LandingPageLink::KIND_REPOSITORY, 'position' => 1]);
    $json = validatedIsoJson($resource);
    $links = collect($json['properties']['distributionInfo'][0]['transferOptions'])->flatMap(fn (array $transfer) => $transfer['onLine']);
    expect($links->pluck('linkage')->all())->toContain('https://example.org/file', 'https://example.org/extra')
        ->not->toContain('https://example.org/fallback')
        ->and($links->firstWhere('linkage', 'https://example.org/repository')['function'])->toBe('information');
});

test('preserves schema-supported formats alternative titles revision dates and series information', function () {
    $resource = isoJsonResource();
    $type = TitleType::firstOrCreate(['slug' => 'AlternativeTitle'], ['name' => 'Alternative Title']);
    $resource->titles()->create(['value' => 'Alternative', 'title_type_id' => $type->id]);
    $resource->formats()->create(['value' => 'application/zip']);
    $series = DescriptionType::firstOrCreate(['slug' => 'SeriesInformation'], ['name' => 'Series Information']);
    $resource->descriptions()->create(['value' => 'Volume 2', 'description_type_id' => $series->id]);
    $resource->forceFill(['created_at' => '2025-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'])->saveQuietly();
    $json = validatedIsoJson($resource);
    expect($json['properties']['identificationInfo']['citation']['alternateTitle'])->toBe(['Alternative'])
        ->and($json['properties']['identificationInfo']['citation']['series'])->toBe(['name' => 'Volume 2'])
        ->and($json['properties']['identificationInfo']['resourceFormat'][0]['formatSpecificationCitation']['title'])->toBe('application/zip')
        ->and($json['properties']['dateInfo']['revision'])->toBe('2026-01-01T00:00:00Z');
});

test('maps timestamp dates while ignoring incomplete ranges and unsafe public URLs', function () {
    $resource = isoJsonResource();
    $type = DateType::firstOrCreate(['slug' => 'Updated'], ['name' => 'Updated']);
    $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => '2026-10-26T09:30:00Z']);
    $type = DateType::firstOrCreate(['slug' => 'Created'], ['name' => 'Created']);
    $resource->dates()->create(['date_type_id' => $type->id, 'start_date' => '2020-01-01', 'end_date' => '2020-12-31']);
    $resource->resourceRights()->create(['rights_text' => 'Terms', 'rights_uri' => 'javascript:alert(1)']);
    $json = validatedIsoJson($resource);
    expect($json['properties']['identificationInfo']['citation']['date'])->toBe(['publication' => '2026', 'revision' => '2026-10-26T09:30:00Z'])
        ->and(json_encode($json))->not->toContain('javascript:', '2020-12-31');
});

test('handles missing resource types and the global ISO switch', function () {
    $profile = app(Iso19115ResourceProfileService::class);
    $resource = new Resource;
    $resource->setRelation('resourceType', null);
    expect($profile->supportsJson($resource))->toBeFalse();
    config(['iso19115.enabled' => false]);
    expect($profile->supportsJson(isoJsonResource()))->toBeFalse();
});
