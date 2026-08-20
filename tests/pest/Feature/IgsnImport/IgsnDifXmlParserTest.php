<?php

use App\Enums\AccessLevel;
use App\Models\Affiliation;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\Size;
use App\Services\IgsnDifXmlParser;
use App\Services\LandingPageResourceTransformer;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);

    $this->parser = new IgsnDifXmlParser;
    $this->resource = Resource::factory()->create();
    $this->igsnMetadata = IgsnMetadata::create([
        'resource_id' => $this->resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);
});

describe('IgsnDifXmlParser', function () {
    it('parses scalar fields from DIF XML with namespace', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample xmlns="http://pmd.gfz-potsdam.de/igsn/schemas/description-ext/1.3">
                        <sample_type>Rock</sample_type>
                        <material>Basalt</material>
                        <user_code>ICDP5068</user_code>
                        <current_archive>GFZ Potsdam</current_archive>
                        <collection_method>Core drilling</collection_method>
                        <depth_min>10.5</depth_min>
                        <depth_max>20.3</depth_max>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->sample_type)->toBe('Rock');
        expect($this->igsnMetadata->material)->toBe('Basalt');
        expect($this->igsnMetadata->user_code)->toBe('ICDP5068');
        expect($this->igsnMetadata->current_archive)->toBe('GFZ Potsdam');
        expect($this->igsnMetadata->collection_method)->toBe('Core drilling');
        expect((float) $this->igsnMetadata->depth_min)->toBe(10.5);
        expect((float) $this->igsnMetadata->depth_max)->toBe(20.3);
    });

    it('parses DIF XML without namespace', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Sediment</sample_type>
                        <material>Clay</material>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->sample_type)->toBe('Sediment');
        expect($this->igsnMetadata->material)->toBe('Clay');
    });

    it('maps geo location from coordinates', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <latitude>52.3759</latitude>
                        <longitude>13.0683</longitude>
                        <country>Germany</country>
                        <city>Potsdam</city>
                        <elevation>34.5</elevation>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();

        $geo = GeoLocation::where('resource_id', $this->resource->id)->first();
        expect($geo)->not->toBeNull();
        expect((float) $geo->point_latitude)->toBe(52.3759);
        expect((float) $geo->point_longitude)->toBe(13.0683);
        expect($geo->place)->toBe('Potsdam, Germany');
    });

    it('maps collection dates', function () {

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <collection_start_date>2020-06-15</collection_start_date>
                        <collection_end_date>2020-06-20</collection_end_date>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();

        $date = ResourceDate::where('resource_id', $this->resource->id)->first();
        expect($date)->not->toBeNull();
        expect($date->date_value)->toBeNull();
        expect($date->start_date)->toBe('2020-06-15');
        expect($date->end_date)->toBe('2020-06-20');
    });

    it('maps collector as ResourceContributor', function () {

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <collector>Müller, Hans</collector>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();

        $contributor = ResourceContributor::where('resource_id', $this->resource->id)->first();
        expect($contributor)->not->toBeNull();
    });

    it('stores parent_igsn handle in description_json for later resolution', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Core</sample_type>
                        <parent_igsn>GFBNO7002EC8H101</parent_igsn>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->description_json)->not->toBeNull();
        expect($this->igsnMetadata->description_json['parent_igsn_handle'])->toBe('GFBNO7002EC8H101');
    });

    it('ignores N/A values in scalar fields', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <material>n/a</material>
                        <collector>N/A</collector>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->sample_type)->toBe('Rock');
        expect($this->igsnMetadata->material)->toBeNull();
    });

    it('returns false for invalid XML', function () {
        $result = $this->parser->enrichFromDifXml('not-xml', $this->resource, $this->igsnMetadata);

        expect($result)->toBeFalse();
    });

    it('returns false when no sample element found', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <Entry_ID>test</Entry_ID>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeFalse();
    });

    it('skips geo location if one already exists', function () {
        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_latitude' => 0.0,
            'point_longitude' => 0.0,
            'position' => 0,
        ]);

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <latitude>52.0</latitude>
                        <longitude>13.0</longitude>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        // Should still have only one geo location (the original)
        expect(GeoLocation::where('resource_id', $this->resource->id)->count())->toBe(1);
        $geo = GeoLocation::where('resource_id', $this->resource->id)->first();
        expect((float) $geo->point_latitude)->toBe(0.0);
    });

    it('adds normalized DIF geometry to an existing place-only DataCite location', function () {
        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'place' => 'DataCite place',
            'position' => 0,
        ]);

        $xml = <<<'XML'
        <DIF><sample>
            <latitude>49.6288</latitude>
            <longitude>8.68799</longitude>
            <latitude_end>49.6344</latitude_end>
            <longitude_end>8.69644</longitude_end>
            <country>Germany</country>
        </sample></DIF>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        $geo = GeoLocation::whereBelongsTo($this->resource)->sole();
        expect($geo->place)->toBe('DataCite place')
            ->and($geo->country)->toBe('Germany')
            ->and($geo->geo_type)->toBe('box')
            ->and((float) $geo->south_bound_latitude)->toBe(49.6288)
            ->and((float) $geo->west_bound_longitude)->toBe(8.68799)
            ->and((float) $geo->north_bound_latitude)->toBe(49.6344)
            ->and((float) $geo->east_bound_longitude)->toBe(8.69644)
            ->and(GeoLocation::whereBelongsTo($this->resource)->count())->toBe(1);
    });

    it('creates geo with place name only when no coordinates', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <country>Iceland</country>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        $geo = GeoLocation::where('resource_id', $this->resource->id)->first();
        expect($geo)->not->toBeNull();
        expect($geo->place)->toBe('Iceland');
        expect($geo->point_latitude)->toBeNull();
    });

    it('adds Collected date even when a Created date already exists', function () {
        $createdTypeId = DateType::where('name', 'Created')->value('id');
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $createdTypeId,
            'date_value' => '2024-01-01',
        ]);

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <collection_start_date>2020-06-15</collection_start_date>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        $collectedTypeId = DateType::where('name', 'Collected')->value('id');
        $collectedDate = ResourceDate::where('resource_id', $this->resource->id)
            ->where('date_type_id', $collectedTypeId)
            ->first();
        expect($collectedDate)->not->toBeNull();
        expect($collectedDate->date_value)->toBe('2020-06-15');

        // Both dates should exist
        expect(ResourceDate::where('resource_id', $this->resource->id)->count())->toBe(2);
    });

    it('does not duplicate a DataCite single date with an equivalent DIF interval', function () {
        $collectedTypeId = DateType::where('name', 'Collected')->value('id');
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $collectedTypeId,
            'date_value' => '2021',
        ]);

        $xml = <<<'XML'
        <DIF><sample>
            <collection_start_date>2021</collection_start_date>
            <collection_end_date>2021</collection_end_date>
        </sample></DIF>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        $dates = ResourceDate::whereBelongsTo($this->resource)
            ->where('date_type_id', $collectedTypeId)
            ->get();
        expect($dates)->toHaveCount(1)
            ->and($dates->sole()->date_value)->toBe('2021')
            ->and($dates->sole()->start_date)->toBeNull()
            ->and($dates->sole()->end_date)->toBeNull();
    });

    it('appends only genuinely distinct collection periods', function () {
        $collectedTypeId = DateType::where('name', 'Collected')->value('id');
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $collectedTypeId,
            'date_value' => '2020',
        ]);

        $xml = <<<'XML'
        <DIF><sample>
            <collection_start_date>2021-01-01</collection_start_date>
            <collection_end_date>2021-01-02</collection_end_date>
        </sample></DIF>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        expect(ResourceDate::whereBelongsTo($this->resource)->where('date_type_id', $collectedTypeId)->count())->toBe(2)
            ->and(ResourceDate::whereBelongsTo($this->resource)->where('start_date', '2021-01-01')->sole()->end_date)->toBe('2021-01-02');
    });

    it('adds DataCollector even when other contributors already exist', function () {
        $otherType = ContributorType::where('slug', '!=', 'DataCollector')->first();
        $person = Person::firstOrCreate(['family_name' => 'Existing', 'given_name' => 'Person']);
        $contributor = ResourceContributor::create([
            'resource_id' => $this->resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => 0,
        ]);
        $contributor->contributorTypes()->sync([$otherType->id]);

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <collector>Müller, Hans</collector>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        // Both contributors should exist
        expect(ResourceContributor::where('resource_id', $this->resource->id)->count())->toBe(2);

        $dataCollectorType = ContributorType::where('slug', 'DataCollector')->first();
        $dataCollector = ResourceContributor::where('resource_id', $this->resource->id)
            ->whereHas('contributorTypes', fn ($q) => $q->where('contributor_types.id', $dataCollectorType->id))
            ->first();
        expect($dataCollector)->not->toBeNull();
    });

    it('maps classification from DIF XML', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <classification>Igneous</classification>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        $classification = IgsnClassification::where('resource_id', $this->resource->id)->first();
        expect($classification)->not->toBeNull();
        expect($classification->value)->toBe('Igneous');
    });

    it('skips classification when N/A', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <classification>N/A</classification>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(IgsnClassification::where('resource_id', $this->resource->id)->count())->toBe(0);
    });

    it('adds a missing classification without replacing an existing value', function () {
        IgsnClassification::create([
            'resource_id' => $this->resource->id,
            'value' => 'Existing',
        ]);

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <classification>Igneous</classification>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(IgsnClassification::where('resource_id', $this->resource->id)->pluck('value')->all())
            ->toBe(['Existing', 'Igneous']);
    });

    it('maps geological age from DIF XML', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <geological_age>Jurassic</geological_age>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        $age = IgsnGeologicalAge::where('resource_id', $this->resource->id)->first();
        expect($age)->not->toBeNull();
        expect($age->value)->toBe('Jurassic');
    });

    it('skips geological age when N/A', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <geological_age>n/a</geological_age>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(IgsnGeologicalAge::where('resource_id', $this->resource->id)->count())->toBe(0);
    });

    it('adds a missing geological age without replacing an existing value', function () {
        IgsnGeologicalAge::create([
            'resource_id' => $this->resource->id,
            'value' => 'Cretaceous',
        ]);

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <geological_age>Jurassic</geological_age>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(IgsnGeologicalAge::where('resource_id', $this->resource->id)->pluck('value')->all())
            ->toBe(['Cretaceous', 'Jurassic']);
    });

    it('maps geological unit from DIF XML', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <geological_unit>Eifel Formation</geological_unit>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        $unit = IgsnGeologicalUnit::where('resource_id', $this->resource->id)->first();
        expect($unit)->not->toBeNull();
        expect($unit->value)->toBe('Eifel Formation');
    });

    it('skips geological unit when N/A', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <geological_unit>N/A</geological_unit>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(IgsnGeologicalUnit::where('resource_id', $this->resource->id)->count())->toBe(0);
    });

    it('adds a missing geological unit without replacing an existing value', function () {
        IgsnGeologicalUnit::create([
            'resource_id' => $this->resource->id,
            'value' => 'Existing Formation',
        ]);

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <geological_unit>Eifel Formation</geological_unit>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(IgsnGeologicalUnit::where('resource_id', $this->resource->id)->pluck('value')->all())
            ->toBe(['Existing Formation', 'Eifel Formation']);
    });

    it('assigns deterministic positions to newly appended ordered IGSN values', function () {
        IgsnClassification::create(['resource_id' => $this->resource->id, 'value' => 'Existing class', 'position' => 4]);
        IgsnGeologicalAge::create(['resource_id' => $this->resource->id, 'value' => 'Existing age', 'position' => 7]);
        IgsnGeologicalUnit::create(['resource_id' => $this->resource->id, 'value' => 'Existing unit', 'position' => 2]);

        $xml = <<<'XML'
        <DIF><sample>
            <classification>First class;Second class</classification>
            <geological_age>First age;Second age</geological_age>
            <geological_unit>First unit;Second unit</geological_unit>
        </sample></DIF>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue()
            ->and(IgsnClassification::whereBelongsTo($this->resource)->orderBy('position')->pluck('position', 'value')->all())->toBe([
                'Existing class' => 4,
                'First class' => 5,
                'Second class' => 6,
            ])->and(IgsnGeologicalAge::whereBelongsTo($this->resource)->orderBy('position')->pluck('position', 'value')->all())->toBe([
                'Existing age' => 7,
                'First age' => 8,
                'Second age' => 9,
            ])->and(IgsnGeologicalUnit::whereBelongsTo($this->resource)->orderBy('position')->pluck('position', 'value')->all())->toBe([
                'Existing unit' => 2,
                'First unit' => 3,
                'Second unit' => 4,
            ]);
    });

    it('skips geo location when country and city are N/A', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <latitude>52.3759</latitude>
                        <longitude>13.0683</longitude>
                        <country>N/A</country>
                        <city>n/a</city>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        $geo = GeoLocation::where('resource_id', $this->resource->id)->first();
        expect($geo)->not->toBeNull();
        // Coordinates should still be set
        expect((float) $geo->point_latitude)->toBe(52.3759);
        expect((float) $geo->point_longitude)->toBe(13.0683);
        // Place should be null (N/A values filtered out)
        expect($geo->place)->toBeNull();
    });

    it('skips geo location entirely when only N/A place and no coordinates', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <country>N/A</country>
                        <city>N/A</city>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(GeoLocation::where('resource_id', $this->resource->id)->count())->toBe(0);
    });

    it('skips collection dates when values are N/A', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <collection_start_date>N/A</collection_start_date>
                        <collection_end_date>n/a</collection_end_date>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect(ResourceDate::where('resource_id', $this->resource->id)->count())->toBe(0);
    });

    it('persists the approved GFLMU0020 record once in canonical shared storage', function () {
        $this->resource->update([
            'doi' => '10.60510/gflmu0020',
            'access_level' => null,
        ]);

        AlternateIdentifier::create([
            'resource_id' => $this->resource->id,
            'value' => 'ODG_1B_1',
            'type' => 'Local',
            'position' => 0,
        ]);
        AlternateIdentifier::create([
            'resource_id' => $this->resource->id,
            'value' => '10273/GFLMU0020',
            'type' => 'IGSN',
            'position' => 1,
        ]);

        $person = Person::create(['given_name' => 'Guido', 'family_name' => 'Blöcher']);
        ResourceCreator::create([
            'resource_id' => $this->resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $xml = file_get_contents(base_path('tests/fixtures/igsn/gflmu0020.xml'));

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue()
            ->and($this->parser->enrichFromDifXml($xml, $this->resource->fresh(), $this->igsnMetadata->fresh()))->toBeTrue();

        $meta = $this->igsnMetadata->fresh();
        expect($meta->user_code)->toBe('Resalt')
            ->and($meta->sample_type)->toBe('Core')
            ->and($meta->sample_purpose)->toBe('Triaxial Compressive Strength test')
            ->and($meta->material)->toBe('Rock')
            ->and($meta->collection_date_precision)->toBe('year')
            ->and($meta->current_archive_contact)->toBe('Lena Muhl')
            ->and($meta->original_archive_contact)->toBe('Guido Blöcher')
            ->and($meta->sample_access)->toBe('Private')
            ->and($meta->description_json['parent_igsn_handle'])->toBe('GFLMU0002')
            ->and($meta->description_json['comments'])->toBe(['Granodiorite'])
            ->and($this->resource->fresh()->access_level)->toBe(AccessLevel::RESTRICTED);

        $geo = GeoLocation::whereBelongsTo($this->resource)->sole();
        expect($geo->geo_type)->toBe('box')
            ->and((float) $geo->south_bound_latitude)->toBe(49.6288)
            ->and((float) $geo->west_bound_longitude)->toBe(8.68799)
            ->and((float) $geo->north_bound_latitude)->toBe(49.6344)
            ->and((float) $geo->east_bound_longitude)->toBe(8.69644)
            ->and($geo->point_latitude)->toBeNull()
            ->and($geo->place)->toBe('Heppenheim/Bergstraße, Germany')
            ->and($geo->country)->toBe('Germany')
            ->and($geo->city)->toBe('Heppenheim');

        expect(AlternateIdentifier::whereBelongsTo($this->resource)->orderBy('position')->get()->map->only(['value', 'type'])->all())
            ->toBe([
                ['value' => 'ODG_1B_1', 'type' => 'Local accession number'],
                ['value' => '10273/GFLMU0020', 'type' => 'IGSN'],
            ])
            ->and(Size::whereBelongsTo($this->resource)->orderBy('id')->get()->map->only(['numeric_value', 'unit', 'type'])->all())
            ->toBe([
                ['numeric_value' => '50.0000', 'unit' => 'mm', 'type' => 'diameter'],
                ['numeric_value' => '100.0000', 'unit' => 'mm', 'type' => 'length'],
            ]);

        $collector = ResourceContributor::whereBelongsTo($this->resource)->sole();
        expect($collector->contributorable_type)->toBe(Person::class)
            ->and($collector->contributorable_id)->toBe($person->id)
            ->and(Affiliation::where('affiliatable_type', ResourceContributor::class)
                ->where('affiliatable_id', $collector->id)->sole()->name)
            ->toBe('GFZ German Research Centre for Geosciences, Potsdam, Germany')
            ->and(ResourceDate::whereBelongsTo($this->resource)->count())->toBe(1)
            ->and(IgsnClassification::whereBelongsTo($this->resource)->count())->toBe(1)
            ->and(IgsnGeologicalUnit::whereBelongsTo($this->resource)->count())->toBe(1);

        $landingTransformer = new LandingPageResourceTransformer;
        $landingResource = Resource::with($landingTransformer->requiredRelations())->findOrFail($this->resource->id);
        $landingData = $landingTransformer->transform($landingResource);

        expect($landingData['igsn_metadata'])->toMatchArray([
            'igsn' => 'GFLMU0020',
            'name' => 'ODG_1B_1',
            'user_code' => 'Resalt',
            'sample_type' => 'Core',
            'material' => 'Rock',
            'sample_access' => 'Private',
            'comments' => ['Granodiorite'],
            'original_archive_contact' => 'Guido Blöcher',
        ])->and($landingData['igsn_metadata']['parent'])->toMatchArray([
            'igsn' => 'GFLMU0002',
            'doi' => '10.60510/gflmu0002',
        ])->and($landingData['igsn_metadata']['geological_units'][0]['value'])->toBe('Weschnitz Pluton')
            ->and($landingData['geo_locations'][0])->toMatchArray([
                'geo_type' => 'box',
                'place' => 'Heppenheim/Bergstraße, Germany',
                'country' => 'Germany',
                'city' => 'Heppenheim',
            ]);
    });
});
