<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnMetadataValueType;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\IgsnMetadata;
use App\Models\IgsnMetadataValue;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Igsn\Export\IgsnCoreExportResult;
use App\Services\Igsn\Export\IgsnCoreGeometryMapper;
use App\Services\Igsn\Export\IgsnCoreMetadataException;
use App\Services\Igsn\Export\IgsnCoreSource;
use App\Services\Igsn\Export\IgsnCoreSourceBuilder;
use App\Services\Igsn\Export\IgsnCoreVocabulary;
use App\Services\Igsn\Export\IgsnCoreXmlExporter;
use App\Services\Igsn\Export\IgsnCoreXmlValidator;
use App\Services\IgsnStorageService;
use Database\Seeders\RelationTypeSeeder;

covers(IgsnCoreXmlExporter::class, IgsnCoreSourceBuilder::class, IgsnCoreGeometryMapper::class, IgsnCoreVocabulary::class, IgsnCoreSource::class, IgsnCoreExportResult::class, IgsnCoreMetadataException::class);

function coreSample(): Resource
{
    $type = ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'PhysicalObject']);
    $resource = Resource::factory()->create(['resource_type_id' => $type->id, 'doi' => '10.58052/IECORE001', 'publication_year' => 2026]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Core & sample <A>', 'language' => 'en']);
    ResourceCreator::factory()->forPerson(Person::factory()->create(['given_name' => 'Anna', 'family_name' => 'Sample']))->create(['resource_id' => $resource->id]);

    return $resource->refresh();
}

function coreXpath(string $xml): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('i', 'urn:FAIR-AIMS:Core:1');
    $xpath->registerNamespace('d', 'http://datacite.org/schema/kernel-4');

    return $xpath;
}

test('exports a physical object without extension metadata through XSLT and the pinned XSD', function () {
    $resource = coreSample();
    $result = app(IgsnCoreXmlExporter::class)->export($resource);
    $validation = app(IgsnCoreXmlValidator::class)->validate($result->xml);
    expect($validation->errors)->toBe([]);
    $xpath = coreXpath($result->xml);
    expect($xpath->evaluate('string(/i:resource/i:identifier)'))->toBe($resource->doi)
        ->and($xpath->evaluate('string(//i:name)'))->toBe('Core & sample <A>')
        ->and($xpath->evaluate('string(//i:creatorName)'))->toBe('Sample, Anna')
        ->and($xpath->evaluate('string(//i:resourceType/@i:resourceTypeGeneral)'))->toBe('PhysicalObject')
        ->and($result->xml)->toContain('/schemas/igsn/2.0/core/'.config('igsn_core.snapshot'))
        ->not->toContain('Unknown', 'igsn.org/schema', 'Core:2');
    expect(app(IgsnCoreXmlExporter::class)->export($resource)->xml)->toBe($result->xml);
});

test('preserves snapshots, text, local names, date precision and current archives without exposing contacts', function () {
    $resource = coreSample();
    $resource->creators()->first()->update(['name_snapshot' => 'Original, Anne', 'given_name_snapshot' => 'Anne', 'family_name_snapshot' => 'Original', 'email' => 'private@example.org']);
    AlternateIdentifier::create(['resource_id' => $resource->id, 'type' => 'Local accession number', 'value' => 'ABC-001', 'position' => 0]);
    IgsnMetadata::create(['resource_id' => $resource->id, 'sample_type' => 'Core', 'material' => 'Rock', 'current_archive' => 'Current collection', 'original_archive' => 'Former collection', 'current_archive_contact' => 'archive-private@example.org', 'collection_date_precision' => 'month', 'collection_method_description' => '<p>Drilled &amp; sampled<br>at depth.</p>']);
    Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => '<p>Größe &amp; Maß</p>', 'language' => 'de']);
    $type = DateType::firstOrCreate(['slug' => 'Collected'], ['name' => 'Collected']);
    foreach ([['date_value' => '2020-02'], ['start_date' => '2019', 'end_date' => null], ['start_date' => null, 'end_date' => '2021-03']] as $date) {
        ResourceDate::create(['resource_id' => $resource->id, 'date_type_id' => $type->id, ...$date]);
    }
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([]);
    $xpath = coreXpath($result->xml);
    expect($xpath->evaluate('string(//i:creatorName)'))->toBe('Original, Anne')
        ->and($xpath->evaluate('string(//i:givenName)'))->toBe('Anne')
        ->and($xpath->evaluate('string(//i:name[1])'))->toBe('ABC-001')
        ->and($xpath->evaluate('string(//i:description[@i:lang="de"])'))->toBe('Größe & Maß')
        ->and($xpath->evaluate('string(//i:date[2])'))->toBe('2019/')
        ->and($xpath->evaluate('string(//i:date[3])'))->toBe('/2021-03')
        ->and($xpath->evaluate('string(//i:date[1]/@i:dateInformation)'))->toBe('month')
        ->and($result->xml)->toContain('Current collection')->not->toContain('private@example.org', 'Former collection', '&amp;amp;', 'contactPerson')
        ->and($result->omissions)->toContain('historical_archive_not_supported');
});

test('reports missing required metadata without synthesizing values', function (string $field) {
    $resource = coreSample();
    match ($field) {
        'identifier' => $resource->update(['doi' => null]),
        'publication_year' => $resource->update(['publication_year' => null]),
        'name' => $resource->titles()->delete(),
        'creators' => $resource->creators()->delete(),
        'publisher' => $resource->publisher->update(['identifier' => null]),
    };
    try {
        app(IgsnCoreXmlExporter::class)->export($resource->fresh());
        $this->fail('Incomplete metadata must fail.');
    } catch (IgsnCoreMetadataException $exception) {
        expect($exception->fields)->toContain($field);
    }
})->with(['identifier', 'publication_year', 'name', 'creators', 'publisher']);

test('keeps the context of Other descriptions required by the Core documentation', function (string $language, string $platformLabel, string $sampleLabel) {
    $resource = coreSample();
    $resource->language()->associate(Language::firstOrCreate(['code' => $language], ['name' => $language]));
    $resource->save();
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'platform_description' => '<p>Polarstern &amp; equipment</p>',
        'description_json' => ['description_groups' => [['entries' => [
            ['value' => '<p>Fine grained &amp; layered</p>', 'scheme' => '<b>Texture</b>'],
            ['value' => 'Black'],
            ['value' => '<p></p>', 'scheme' => 'Empty'],
        ]]]],
    ]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    $xpath = coreXpath($result->xml);
    $descriptions = '//i:description[@i:descriptionType="Other"]';

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and($xpath->evaluate('count('.$descriptions.')'))->toBe(3.0)
        ->and($xpath->evaluate('string(('.$descriptions.')[1])'))->toBe($platformLabel.': Polarstern & equipment')
        ->and($xpath->evaluate('string(('.$descriptions.')[2])'))->toBe('Texture: Fine grained & layered')
        ->and($xpath->evaluate('string(('.$descriptions.')[3])'))->toBe($sampleLabel.': Black')
        ->and($xpath->evaluate('string(('.$descriptions.')[1]/@i:lang)'))->toBe($language)
        ->and($result->xml)->not->toContain('&amp;amp;', '<b>', 'Empty:');
})->with([['en', 'Platform', 'Sample description'], ['de', 'Plattform', 'Probenbeschreibung']]);

test('does not relabel foreign titles as English', function () {
    $resource = coreSample();
    $resource->titles()->update(['language' => 'de']);
    expect(fn () => app(IgsnCoreXmlExporter::class)->export($resource->fresh()))->toThrow(IgsnCoreMetadataException::class);
    AlternateIdentifier::create(['resource_id' => $resource->id, 'type' => 'Local accession number', 'value' => 'ABC-002', 'position' => 0]);
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect($result->omissions)->toContain('title_language_unsupported')
        ->and(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([]);
});

test('exports imported titles and descriptions without language annotations', function () {
    ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'PhysicalObject']);
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    DateType::firstOrCreate(['slug' => 'Collected'], ['name' => 'Collected']);
    $import = app(IgsnStorageService::class)->storeFromCsv([[
        'igsn' => '10.58052/IEIMPORTED001',
        'title' => 'Imported sample title',
        'depth_min' => null,
        'depth_max' => null,
        'collection_start_date' => '2026-03-01',
        'collection_method_descr' => 'Collected by drilling',
        'platform_descr' => 'Research vessel',
        'description' => json_encode([['description' => 'Dark sample', 'descriptionScheme' => 'Colour']], JSON_THROW_ON_ERROR),
        '_creator' => ['familyName' => 'Collector', 'givenName' => 'Anne'],
    ]], 'core-language-regression.csv');
    expect($import['errors'])->toBe([])->and($import['created'])->toBe(1);
    $resource = Resource::where('doi', '10.58052/IEIMPORTED001')->firstOrFail();
    expect($resource->language_id)->toBeNull()->and($resource->titles()->first()->language)->toBeNull();

    $result = app(IgsnCoreXmlExporter::class)->export($resource);
    $xpath = coreXpath($result->xml);

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and($xpath->evaluate('string(//i:name)'))->toBe('Imported sample title')
        ->and($xpath->evaluate('string(//i:name/@i:lang)'))->toBe('en')
        ->and($xpath->evaluate('count(//i:description[@i:lang="en"])'))->toBe(3.0)
        ->and($result->xml)->toContain('Collected by drilling', 'Platform: Research vessel', 'Colour: Dark sample')
        ->and($result->omissions)->not->toContain('title_language_unsupported', 'description_type_or_language_unsupported', 'resource_language_unsupported');
});

test('uses the Core language default for missing title and description languages', function (?string $missing) {
    $resource = coreSample();
    $resource->update(['language_id' => null]);
    $resource->titles()->update(['language' => $missing]);
    $description = Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => 'Unlabelled abstract', 'language' => $missing]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    $xpath = coreXpath($result->xml);

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and($xpath->evaluate('string(//i:name)'))->toBe('Core & sample <A>')
        ->and($xpath->evaluate('string(//i:name/@i:lang)'))->toBe('en')
        ->and($xpath->evaluate('string(//i:description)'))->toBe('Unlabelled abstract')
        ->and($xpath->evaluate('string(//i:description/@i:lang)'))->toBe('en')
        ->and($xpath->evaluate('string(//i:language)'))->toBe('en')
        ->and($resource->fresh()->language_id)->toBeNull()
        ->and($resource->titles()->first()->language)->toBe($missing)
        ->and($description->fresh()->language)->toBeNull();
})->with([null, '', ' ']);

test('uses the Core language default for a publisher without a language', function (?string $missing) {
    $resource = coreSample();
    // The current publisher column is NOT NULL; also cover an incomplete hydrated model.
    $resource->publisher->setAttribute('language', $missing);

    $result = app(IgsnCoreXmlExporter::class)->export($resource);

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and(coreXpath($result->xml)->evaluate('string(//i:publisher/@i:lang)'))->toBe('en')
        ->and($resource->publisher->getAttribute('language'))->toBe($missing);
})->with([null, '', ' ']);

test('preserves explicit and inherited export languages ahead of the Core default', function (?string $resourceLanguage, ?string $fieldLanguage, ?string $descriptionLanguage, bool $titleSupported) {
    $resource = coreSample();
    $resource->update(['language_id' => $resourceLanguage === null ? null : Language::firstOrCreate(['code' => $resourceLanguage], ['name' => $resourceLanguage])->id]);
    $resource->titles()->update(['language' => $fieldLanguage]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => 'Explicit language abstract', 'language' => $fieldLanguage]);
    AlternateIdentifier::create(['resource_id' => $resource->id, 'type' => 'Local sample name', 'value' => 'LOCAL-LANGUAGE-SAMPLE', 'position' => 0]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    $xpath = coreXpath($result->xml);

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and($xpath->evaluate('count(//i:name[@i:lang="en"])'))->toBe($titleSupported ? 1.0 : 0.0)
        ->and($xpath->evaluate('string(//i:description/@i:lang)'))->toBe($descriptionLanguage ?? '')
        ->and($xpath->evaluate('count(//i:description)'))->toBe($descriptionLanguage === null ? 0.0 : 1.0)
        ->and($xpath->evaluate('string(//i:language)'))->toBe($resourceLanguage === null ? 'en' : '');
    if (! $titleSupported) {
        expect($result->omissions)->toContain('title_language_unsupported');
    }
    if ($descriptionLanguage === null) {
        expect($result->omissions)->toContain('description_type_or_language_unsupported');
    }
    if ($resourceLanguage !== null) {
        expect($result->omissions)->toContain('resource_language_unsupported');
    }
})->with([
    'inherit German' => ['de', null, 'de', false],
    'inherit unsupported French' => ['fr', null, null, false],
    'field overrides German resource' => ['de', 'en', 'en', true],
    'explicit German without resource language' => [null, 'de', 'de', false],
    'explicit French without resource language' => [null, 'fr', null, false],
    'English language tag without resource language' => [null, 'en-GB', 'en', true],
]);

test('does not default an explicitly unsupported publisher language to English', function (string $language) {
    $resource = coreSample();
    $resource->publisher->update(['language' => $language]);

    try {
        app(IgsnCoreXmlExporter::class)->export($resource);
        $this->fail('An unsupported publisher language must remain an error.');
    } catch (IgsnCoreMetadataException $exception) {
        expect($exception->fields)->toContain('publisher');
    }
})->with(['de', 'fr']);

test('deduplicates identical geometry and omits ambiguous or unsupported coordinates', function () {
    $resource = coreSample();
    foreach ([0, 1] as $position) {
        GeoLocation::factory()->create(['resource_id' => $resource->id, 'place' => 'Potsdam', 'point_longitude' => 13.1, 'point_latitude' => 52.4, 'position' => $position]);
    }
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and(coreXpath($result->xml)->evaluate('count(//i:geoLocationPoint)'))->toBe(1.0);
    $resource->geoLocations()->orderBy('position')->first()->update(['point_longitude' => 14]);
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect($result->omissions)->toContain('point_ambiguous')
        ->and(coreXpath($result->xml)->evaluate('count(//i:geoLocationPoint)'))->toBe(0.0);
    IgsnMetadata::create(['resource_id' => $resource->id, 'coordinate_system' => 'EPSG:3857']);
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect($result->omissions)->toContain('coordinate_system_unsupported')
        ->and($result->xml)->toContain('Potsdam')->not->toContain('geoLocationPoint');
});

test('refuses a regular dataset', function () {
    expect(fn () => app(IgsnCoreXmlExporter::class)->export(Resource::factory()->create()))->toThrow(InvalidArgumentException::class);
});

test('preserves institutional identities, contributor roles, controlled materials and funding', function () {
    $resource = coreSample();
    $institution = Institution::factory()->create(['name' => 'Sample Institute', 'name_identifier' => 'https://ror.org/04z8jg394', 'name_identifier_scheme' => 'ROR', 'scheme_uri' => 'https://ror.org/']);
    $creator = ResourceCreator::factory()->forInstitution($institution)->create(['resource_id' => $resource->id, 'position' => 2]);
    $creator->affiliations()->create(['name' => 'University', 'identifier' => 'https://ror.org/03yrm5c26', 'identifier_scheme' => 'ROR', 'scheme_uri' => 'https://ror.org/']);
    $contributor = ResourceContributor::factory()->create(['resource_id' => $resource->id, 'contributorable_type' => Institution::class, 'contributorable_id' => $institution->id]);
    $contributor->contributorTypes()->detach();
    foreach (['DataCurator', 'HostingInstitution'] as $role) {
        $type = ContributorType::firstOrCreate(['slug' => $role], ['name' => $role]);
        $contributor->contributorTypes()->attach($type);
    }
    IgsnMetadata::create(['resource_id' => $resource->id, 'material' => 'Basalt']);
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'Basalt', 'subject_scheme' => 'Lithology', 'scheme_uri' => 'https://example.org/lithology', 'value_uri' => 'https://example.org/lithology/basalt', 'classification_code' => 'B', 'language' => 'en']);
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'unrelated keyword']);
    $funderType = FunderIdentifierType::firstOrCreate(['slug' => 'ROR'], ['name' => 'ROR']);
    FundingReference::create(['resource_id' => $resource->id, 'funder_name' => 'Funding body', 'funder_identifier' => 'https://ror.org/04z8jg394', 'funder_identifier_type_id' => $funderType->id, 'scheme_uri' => 'https://ror.org/', 'award_number' => 'A-123', 'award_uri' => 'https://example.org/awards/123', 'award_title' => 'Sample project']);
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([]);
    $xpath = coreXpath($result->xml);
    expect($xpath->evaluate('string(//i:creator[2]/i:creatorName/@i:nameType)'))->toBe('Organizational')
        ->and($xpath->evaluate('string(//i:creator[2]/i:nameIdentifier/@nameIdentifierScheme)'))->toBe('ROR')
        ->and($xpath->evaluate('string(//i:affiliation/@affiliationIdentifierScheme)'))->toBe('ROR')
        ->and($xpath->evaluate('count(//i:contributor)'))->toBe(2.0)
        ->and($xpath->evaluate('string(//i:material/@i:valueURI)'))->toBe('https://example.org/lithology/basalt')
        ->and($xpath->evaluate('string(//i:awardNumber/@i:awardURI)'))->toBe('https://example.org/awards/123')
        ->and($result->xml)->not->toContain('unrelated keyword');
});

test('only assigns collector roles to uniquely matched existing typed creators', function (bool $ambiguous) {
    $resource = coreSample();
    IgsnMetadataValue::create(['resource_id' => $resource->id, 'type' => IgsnMetadataValueType::SampledBy, 'value' => 'Sample, Anna', 'normalized_value_hash' => hash('sha256', 'Sample, Anna'), 'position' => 0]);
    if ($ambiguous) {
        ResourceCreator::factory()->forPerson(Person::factory()->create(['given_name' => 'Anna', 'family_name' => 'Sample']))->create(['resource_id' => $resource->id, 'position' => 2]);
    }
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect(coreXpath($result->xml)->evaluate('count(//i:creator[@i:creatorType="collector"])'))->toBe($ambiguous ? 0.0 : 1.0);
    if ($ambiguous) {
        expect($result->omissions)->toContain('sampled_by_unresolved');
    }
})->with([false, true]);

test('exports relation vocabulary slugs from the real seeded display names', function (string $slug, string $name) {
    $this->seed(RelationTypeSeeder::class);
    $resource = coreSample();
    $type = IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI']);
    $relation = RelationType::where('slug', $slug)->firstOrFail();
    expect($relation->name)->toBe($name);
    RelatedIdentifier::create(['resource_id' => $resource->id, 'identifier' => '10.1234/related', 'identifier_type_id' => $type->id, 'relation_type_id' => $relation->id, 'position' => 0]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and(coreXpath($result->xml)->evaluate('string(//i:relatedIdentifier/@i:relationType)'))->toBe($slug)
        ->and(coreXpath($result->xml)->evaluate('string(//i:relatedIdentifier)'))->toBe('10.1234/related')
        ->and($result->omissions)->not->toContain('related_identifier_unsupported');
})->with([['IsPartOf', 'Is Part Of'], ['IsDescribedBy', 'Is Described By']]);

dataset('core parent identifier representations', [
    'DOI' => ['DOI', '10.58052/IEPARENT001'],
    'lowercase DOI' => ['DOI', '10.58052/ieparent001'],
    'DOI resolver' => ['DOI', 'https://doi.org/10.58052/ieparent001'],
    'URL resolver' => ['URL', 'https://doi.org/10.58052/IEPARENT001'],
    'legacy resolver and mixed casing' => ['URL', 'HTTP://DX.DOI.ORG/10.58052/IeParent001'],
    'IGSN handle' => ['IGSN', 'IEPARENT001'],
    'lowercase IGSN handle' => ['IGSN', 'ieparent001'],
    'IGSN DOI' => ['IGSN', '10.58052/IEPARENT001'],
    'IGSN resolver' => ['IGSN', 'https://doi.org/10.58052/IEPARENT001'],
]);

test('hides equivalent identifiers of private or unpublished parents for supported relations', function (string $scheme, string $identifier, ?bool $published, bool $private) {
    config(['datacite.production.igsn_prefix' => '10.58052']);
    $resource = coreSample();
    $parent = Resource::factory()->create(['resource_type_id' => $resource->resource_type_id, 'doi' => '10.58052/IEPARENT001']);
    IgsnMetadata::create(['resource_id' => $parent->id, 'is_private' => $private]);
    if ($published !== null) {
        LandingPage::factory()->create(['resource_id' => $parent->id, 'doi_prefix' => $parent->doi, 'is_published' => $published]);
    }
    IgsnMetadata::create(['resource_id' => $resource->id, 'parent_resource_id' => $parent->id]);
    $type = IdentifierType::firstOrCreate(['slug' => $scheme], ['name' => $scheme]);
    $relation = RelationType::firstOrCreate(['slug' => 'References'], ['name' => 'References']);
    RelatedIdentifier::create(['resource_id' => $resource->id, 'identifier' => $identifier, 'identifier_type_id' => $type->id, 'relation_type_id' => $relation->id, 'position' => 0]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and(coreXpath($result->xml)->evaluate('count(//i:parentIdentifier | //i:relatedIdentifier)'))->toBe(0.0)
        ->and(strtolower($result->xml))->not->toContain('ieparent001');
})->with('core parent identifier representations')->with([
    'private' => [true, true],
    'unpublished' => [false, false],
    'no landing page' => [null, false],
]);

test('deduplicates equivalent public parent assertions while preserving other relations', function (string $scheme, string $identifier) {
    $this->seed(RelationTypeSeeder::class);
    config(['datacite.production.igsn_prefix' => '10.58052']);
    $resource = coreSample();
    $parent = Resource::factory()->create(['resource_type_id' => $resource->resource_type_id, 'doi' => '10.58052/IEPARENT001']);
    IgsnMetadata::create(['resource_id' => $parent->id, 'is_private' => false]);
    LandingPage::factory()->create(['resource_id' => $parent->id, 'doi_prefix' => $parent->doi, 'is_published' => true]);
    IgsnMetadata::create(['resource_id' => $resource->id, 'parent_resource_id' => $parent->id]);
    $type = IdentifierType::firstOrCreate(['slug' => $scheme], ['name' => $scheme]);
    foreach (['IsPartOf', 'References'] as $position => $slug) {
        $relation = RelationType::where('slug', $slug)->firstOrFail();
        RelatedIdentifier::create(['resource_id' => $resource->id, 'identifier' => $identifier, 'identifier_type_id' => $type->id, 'relation_type_id' => $relation->id, 'position' => $position]);
    }

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    $xpath = coreXpath($result->xml);

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and($xpath->evaluate('string(//i:parentIdentifier)'))->toBe('IEPARENT001')
        ->and($xpath->evaluate('count(//i:relatedIdentifier)'))->toBe(1.0)
        ->and($xpath->evaluate('string(//i:relatedIdentifier/@i:relationType)'))->toBe('References')
        ->and($xpath->evaluate('string(//i:relatedIdentifier)'))->toBe($identifier)
        ->and($result->omissions)->not->toContain('related_identifier_unsupported');
})->with('core parent identifier representations');

test('does not conflate other identifier schemes or DOI namespaces with a hidden parent', function (string $scheme, string $identifier) {
    config(['datacite.production.igsn_prefix' => '10.58052']);
    $resource = coreSample();
    $parent = Resource::factory()->create(['doi' => '10.58052/IEPARENT001']);
    IgsnMetadata::create(['resource_id' => $parent->id, 'is_private' => true]);
    IgsnMetadata::create(['resource_id' => $resource->id, 'parent_resource_id' => $parent->id]);
    $type = IdentifierType::firstOrCreate(['slug' => $scheme], ['name' => $scheme]);
    $relation = RelationType::firstOrCreate(['slug' => 'References'], ['name' => 'References']);
    RelatedIdentifier::create(['resource_id' => $resource->id, 'identifier' => $identifier, 'identifier_type_id' => $type->id, 'relation_type_id' => $relation->id, 'position' => 0]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and(coreXpath($result->xml)->evaluate('string(//i:relatedIdentifier)'))->toBe($identifier);
})->with([
    ['Handle', 'IEPARENT001'],
    ['DOI', '10.9999/IEPARENT001'],
    ['URL', 'https://example.org/10.58052/IEPARENT001'],
    ['URL', 'https://doi.org.example.org/10.58052/IEPARENT001'],
]);

test('exports public parent handles once and hides private or unpublished parents', function (bool $published, bool $private, string $doi) {
    $this->seed(RelationTypeSeeder::class);
    config(['datacite.production.igsn_prefix' => '10.58052']);
    $resource = coreSample();
    $parent = Resource::factory()->create(['resource_type_id' => $resource->resource_type_id, 'doi' => $doi]);
    IgsnMetadata::create(['resource_id' => $parent->id, 'is_private' => $private]);
    LandingPage::factory()->create(['resource_id' => $parent->id, 'doi_prefix' => $doi, 'is_published' => $published]);
    IgsnMetadata::create(['resource_id' => $resource->id, 'parent_resource_id' => $parent->id]);
    $type = IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI']);
    $relation = RelationType::where('slug', 'IsPartOf')->firstOrFail();
    RelatedIdentifier::create(['resource_id' => $resource->id, 'identifier' => $doi, 'identifier_type_id' => $type->id, 'relation_type_id' => $relation->id, 'position' => 0]);
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([]);
    $xpath = coreXpath($result->xml);
    if (! $published || $private) {
        expect($result->xml)->not->toContain($doi, 'IEPARENT001');
    } elseif (str_starts_with($doi, '10.58052/')) {
        expect($xpath->evaluate('string(//i:parentIdentifier)'))->toBe('IEPARENT001')
            ->and($xpath->evaluate('count(//i:relatedIdentifier)'))->toBe(0.0);
    } else {
        expect($xpath->evaluate('count(//i:parentIdentifier)'))->toBe(0.0)
            ->and($xpath->evaluate('string(//i:relatedIdentifier)'))->toBe($doi);
    }
})->with([[true, false, '10.58052/IEPARENT001'], [false, false, '10.58052/IEPARENT001'], [true, true, '10.58052/IEPARENT001'], [true, false, '10.9999/IEPARENT001']]);

test('retains dateline boxes and closed polygons with DataCite coordinate children', function () {
    $resource = coreSample();
    GeoLocation::factory()->withBox(170, -170, -20, 20)->create(['resource_id' => $resource->id]);
    GeoLocation::factory()->create(['resource_id' => $resource->id, 'polygon_points' => [['longitude' => 0, 'latitude' => 0], ['longitude' => 2, 'latitude' => 0], ['longitude' => 2, 'latitude' => 2], ['longitude' => 0, 'latitude' => 0]]]);
    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([]);
    $xpath = coreXpath($result->xml);
    expect($xpath->evaluate('string(//i:geoLocationBox/d:eastBoundLongitude)'))->toBe('-170')
        ->and($xpath->evaluate('count(//i:polygonPoint/d:pointLatitude)'))->toBe(4.0);
});

test('does not export closed sampling lines as polygons or let them obscure a polygon', function (bool $withPolygon) {
    $resource = coreSample();
    GeoLocation::factory()->create(['resource_id' => $resource->id, 'geo_type' => 'line', 'place' => 'Sampling track', 'polygon_points' => [
        ['longitude' => 10, 'latitude' => 10],
        ['longitude' => 12, 'latitude' => 10],
        ['longitude' => 12, 'latitude' => 12],
        ['longitude' => 10, 'latitude' => 10],
    ]]);
    if ($withPolygon) {
        GeoLocation::factory()->withPolygon([
            ['longitude' => 0, 'latitude' => 0],
            ['longitude' => 2, 'latitude' => 0],
            ['longitude' => 2, 'latitude' => 2],
            ['longitude' => 0, 'latitude' => 0],
        ])->create(['resource_id' => $resource->id]);
    }

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    $xpath = coreXpath($result->xml);

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and($xpath->evaluate('count(//i:geoLocationPolygon)'))->toBe($withPolygon ? 1.0 : 0.0)
        ->and($result->xml)->toContain('Sampling track')
        ->and($result->omissions)->not->toContain('polygon_ambiguous', 'polygon_invalid');
    if ($withPolygon) {
        expect($xpath->evaluate('string(//i:polygonPoint[1]/d:pointLongitude)'))->toBe('0');
    }
})->with([false, true]);

test('closes editor polygons for Core export without modifying stored vertices', function (array $vertices) {
    $resource = coreSample();
    $location = GeoLocation::factory()->withPolygon($vertices)->create(['resource_id' => $resource->id, 'place' => 'Research area']);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    $xpath = coreXpath($result->xml);

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and($xpath->evaluate('count(//i:polygonPoint)'))->toBe((float) count($vertices) + 1)
        ->and($xpath->evaluate('string(//i:polygonPoint[last()]/d:pointLongitude)'))->toBe('13')
        ->and($xpath->evaluate('string(//i:polygonPoint[last()]/d:pointLatitude)'))->toBe('52')
        ->and($result->omissions)->not->toContain('polygon_invalid')
        ->and($location->fresh()->polygon_points)->toBe($vertices);

    // The same ring with explicit closure must remain a single candidate.
    GeoLocation::factory()->withPolygon([...$vertices, $vertices[0]])->create(['resource_id' => $resource->id, 'place' => 'Research area']);
    $duplicate = app(IgsnCoreXmlExporter::class)->export($resource->fresh());
    expect($duplicate->xml)->toBe($result->xml)
        ->and($duplicate->omissions)->not->toContain('polygon_ambiguous');
})->with([
    'editor triangle' => [[['longitude' => 13, 'latitude' => 52], ['longitude' => 14, 'latitude' => 52], ['longitude' => 14, 'latitude' => 53]]],
    'four vertices' => [[['longitude' => 13, 'latitude' => 52], ['longitude' => 14, 'latitude' => 52], ['longitude' => 14, 'latitude' => 53], ['longitude' => 13, 'latitude' => 53]]],
]);

test('does not repair invalid polygon coordinates or rings with too few vertices', function (array $vertices) {
    $resource = coreSample();
    GeoLocation::factory()->withPolygon($vertices)->create(['resource_id' => $resource->id]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and(coreXpath($result->xml)->evaluate('count(//i:geoLocationPolygon)'))->toBe(0.0)
        ->and($result->omissions)->toContain('polygon_invalid');
})->with([
    'two vertices' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 2, 'latitude' => 2]]],
    'closed two-vertex ring' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 2, 'latitude' => 2], ['longitude' => 0, 'latitude' => 0]]],
    'out-of-range coordinate' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 2, 'latitude' => 2], ['longitude' => 181, 'latitude' => 0]]],
    'missing coordinate' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 2, 'latitude' => 2], ['longitude' => 1]]],
    'repeated single vertex' => [[['longitude' => 1, 'latitude' => 1], ['longitude' => 1, 'latitude' => 1], ['longitude' => 1, 'latitude' => 1], ['longitude' => 1, 'latitude' => 1]]],
    'two distinct vertices repeated' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 2, 'latitude' => 2], ['longitude' => 0, 'latitude' => 0], ['longitude' => 2, 'latitude' => 2]]],
    'collinear triangle' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 1, 'latitude' => 1], ['longitude' => 2, 'latitude' => 2]]],
    'collinear across dateline' => [[['longitude' => 179, 'latitude' => 0], ['longitude' => -180, 'latitude' => 1], ['longitude' => -179, 'latitude' => 2]]],
    'antimeridian aliases' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 180, 'latitude' => 1], ['longitude' => -180, 'latitude' => 1]]],
]);

test('retains non-collinear polygons at small scales and across the dateline', function (array $vertices) {
    $resource = coreSample();
    GeoLocation::factory()->withPolygon($vertices)->create(['resource_id' => $resource->id]);

    $result = app(IgsnCoreXmlExporter::class)->export($resource->fresh());

    expect(app(IgsnCoreXmlValidator::class)->validate($result->xml)->errors)->toBe([])
        ->and(coreXpath($result->xml)->evaluate('count(//i:geoLocationPolygon)'))->toBe(1.0)
        ->and($result->omissions)->not->toContain('polygon_invalid');
})->with([
    'small triangle' => [[['longitude' => 13, 'latitude' => 52], ['longitude' => 13.00000001, 'latitude' => 52], ['longitude' => 13, 'latitude' => 52.00000001]]],
    'dateline triangle' => [[['longitude' => 179, 'latitude' => 0], ['longitude' => -179, 'latitude' => 0], ['longitude' => -179, 'latitude' => 1]]],
    'repeated first vertex' => [[['longitude' => 0, 'latitude' => 0], ['longitude' => 0, 'latitude' => 0], ['longitude' => 1, 'latitude' => 0], ['longitude' => 1, 'latitude' => 1]]],
]);

test('blocks XSLT document access to local files and remote URLs', function (string $uri) {
    $resource = coreSample();
    $path = tempnam(sys_get_temp_dir(), 'core-xsl');
    file_put_contents($path, '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><result><xsl:copy-of select="document(\''.$uri.'\')"/></result></xsl:template></xsl:stylesheet>');
    config(['igsn_core.stylesheet' => $path]);
    $previous = libxml_use_internal_errors();
    try {
        expect(fn () => app(IgsnCoreXmlExporter::class)->export($resource))->toThrow(RuntimeException::class, 'transformation failed');
        expect(libxml_use_internal_errors())->toBe($previous);
    } finally {
        unlink($path);
    }
})->with(['file:///etc/passwd', 'https://example.org/metadata.xml']);
