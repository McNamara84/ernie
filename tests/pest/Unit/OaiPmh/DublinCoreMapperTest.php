<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Format;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\OaiPmh\DublinCoreMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mapper = new DublinCoreMapper;
    $this->resource = Resource::factory()->create([
        'doi' => '10.5880/GFZ.1.2.2024.001',
        'publication_year' => 2024,
    ]);
    $this->titleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true],
    );
});

it('maps dc:title from resource titles', function () {
    Title::create(['resource_id' => $this->resource->id, 'value' => 'My Test Dataset', 'title_type_id' => $this->titleType->id]);

    $this->resource->load('titles');
    $dc = $this->mapper->map($this->resource);

    expect($dc['title'])->toBe(['My Test Dataset']);
});

it('maps dc:identifier as DOI URL', function () {
    $this->resource->load('titles');
    $dc = $this->mapper->map($this->resource);

    expect($dc['identifier'])->toBe(['https://doi.org/10.5880/GFZ.1.2.2024.001']);
});

it('maps dc:date from publication year', function () {
    $this->resource->load('titles');
    $dc = $this->mapper->map($this->resource);

    expect($dc['date'])->toBe(['2024']);
});

it('maps dc:publisher from resource publisher', function () {
    $this->resource->load(['publisher', 'titles']);
    $dc = $this->mapper->map($this->resource);

    expect($dc['publisher'])->toBe(['GFZ Data Services']);
});

it('maps dc:type from resource type', function () {
    $this->resource->load(['resourceType', 'titles']);
    $dc = $this->mapper->map($this->resource);

    expect($dc['type'])->toBe(['Dataset']);
});

it('maps dc:subject from resource subjects', function () {
    Subject::create(['resource_id' => $this->resource->id, 'value' => 'Geophysics']);
    Subject::create(['resource_id' => $this->resource->id, 'value' => 'Seismology']);

    $this->resource->load('subjects');
    $dc = $this->mapper->map($this->resource);

    expect($dc['subject'])->toBe(['Geophysics', 'Seismology']);
});

it('maps dc:language from resource language', function () {
    $this->resource->load(['language', 'titles']);
    $dc = $this->mapper->map($this->resource);

    expect($dc['language'])->toBe(['en']);
});

it('maps dc:format from resource formats', function () {
    Format::create(['resource_id' => $this->resource->id, 'value' => 'application/pdf']);

    $this->resource->load('formats');
    $dc = $this->mapper->map($this->resource);

    expect($dc['format'])->toBe(['application/pdf']);
});

it('maps dc:rights with URI when available', function () {
    $right = Right::firstOrCreate(
        ['identifier' => 'CC-BY-4.0'],
        ['name' => 'CC BY 4.0', 'uri' => 'https://creativecommons.org/licenses/by/4.0/', 'identifier' => 'CC-BY-4.0'],
    );
    $this->resource->rights()->attach($right->id);

    $this->resource->load('rights');
    $dc = $this->mapper->map($this->resource);

    expect($dc['rights'][0])->toContain('CC BY 4.0')
        ->and($dc['rights'][0])->toContain('https://creativecommons.org/licenses/by/4.0/');
});

it('returns empty array for resource with no metadata', function () {
    $resource = Resource::factory()->create([
        'doi' => null,
        'publication_year' => null,
        'publisher_id' => null,
        'language_id' => null,
        'resource_type_id' => null,
    ]);

    $resource->load([
        'titles', 'creators', 'subjects', 'descriptions', 'publisher',
        'contributors', 'resourceType', 'formats', 'relatedIdentifiers',
        'geoLocations', 'rights', 'language',
    ]);

    $dc = $this->mapper->map($resource);

    expect($dc)->toBe([]);
});

it('maps dc:creator from person creators', function () {
    $person = Person::create([
        'family_name' => 'Doe',
        'given_name' => 'John',
    ]);

    ResourceCreator::create([
        'resource_id' => $this->resource->id,
        'creatorable_id' => $person->id,
        'creatorable_type' => Person::class,
        'position' => 0,
    ]);

    $this->resource->load('creators.creatorable');
    $dc = $this->mapper->map($this->resource);

    expect($dc['creator'])->toContain('Doe, John');
});

it('maps dc:creator from institution creators', function () {
    $institution = Institution::create([
        'name' => 'GFZ Helmholtz Centre Potsdam',
    ]);

    ResourceCreator::create([
        'resource_id' => $this->resource->id,
        'creatorable_id' => $institution->id,
        'creatorable_type' => Institution::class,
        'position' => 0,
    ]);

    $this->resource->load('creators.creatorable');
    $dc = $this->mapper->map($this->resource);

    expect($dc['creator'])->toContain('GFZ Helmholtz Centre Potsdam');
});

it('maps dc:description from resource descriptions', function () {
    $descType = DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true],
    );

    Description::create([
        'resource_id' => $this->resource->id,
        'value' => 'A comprehensive dataset about seismic activity',
        'description_type_id' => $descType->id,
    ]);

    $this->resource->load('descriptions.descriptionType');
    $dc = $this->mapper->map($this->resource);

    expect($dc['description'])->toBe(['A comprehensive dataset about seismic activity']);
});

it('maps dc:contributor from person contributors', function () {
    $person = Person::create([
        'family_name' => 'Smith',
        'given_name' => 'Jane',
    ]);

    ResourceContributor::create([
        'resource_id' => $this->resource->id,
        'contributorable_id' => $person->id,
        'contributorable_type' => Person::class,
        'position' => 0,
    ]);

    $this->resource->load('contributors.contributorable');
    $dc = $this->mapper->map($this->resource);

    expect($dc['contributor'])->toContain('Smith, Jane');
});

it('maps dc:relation from related identifiers', function () {
    $identifierType = \App\Models\IdentifierType::firstOrCreate(
        ['slug' => 'DOI'],
        ['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true],
    );

    $relationType = \App\Models\RelationType::firstOrCreate(
        ['slug' => 'References'],
        ['name' => 'References', 'slug' => 'References', 'is_active' => true],
    );

    RelatedIdentifier::create([
        'resource_id' => $this->resource->id,
        'identifier' => 'https://doi.org/10.1234/related',
        'identifier_type_id' => $identifierType->id,
        'relation_type_id' => $relationType->id,
        'position' => 0,
    ]);

    $this->resource->load('relatedIdentifiers');
    $dc = $this->mapper->map($this->resource);

    expect($dc['relation'])->toBe(['https://doi.org/10.1234/related']);
});

it('maps dc:coverage from geo locations with place', function () {
    GeoLocation::create([
        'resource_id' => $this->resource->id,
        'place' => 'Potsdam, Germany',
    ]);

    $this->resource->load('geoLocations');
    $dc = $this->mapper->map($this->resource);

    expect($dc['coverage'])->toBe(['Potsdam, Germany']);
});

it('maps dc:coverage from geo locations with point coordinates', function () {
    GeoLocation::create([
        'resource_id' => $this->resource->id,
        'point_latitude' => 52.3906,
        'point_longitude' => 13.0645,
    ]);

    $this->resource->load('geoLocations');
    $dc = $this->mapper->map($this->resource);

    expect($dc['coverage'][0])->toContain('Point(')
        ->and($dc['coverage'][0])->toContain('52.3906')
        ->and($dc['coverage'][0])->toContain('13.0645');
});

it('maps dc:coverage from geo locations with bounding box', function () {
    GeoLocation::create([
        'resource_id' => $this->resource->id,
        'south_bound_latitude' => 50.0,
        'west_bound_longitude' => 10.0,
        'north_bound_latitude' => 55.0,
        'east_bound_longitude' => 15.0,
    ]);

    $this->resource->load('geoLocations');
    $dc = $this->mapper->map($this->resource);

    expect($dc['coverage'][0])->toContain('Box(')
        ->and($dc['coverage'][0])->toContain('50')
        ->and($dc['coverage'][0])->toContain('15');
});
