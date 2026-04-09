<?php

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
use App\Models\ResourceDate;
use App\Services\IgsnDifXmlParser;

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
        expect($date->date_value)->toContain('2020-06-15');
        expect($date->date_value)->toContain('2020-06-20');
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

    it('skips classification if one already exists', function () {
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

        expect(IgsnClassification::where('resource_id', $this->resource->id)->count())->toBe(1);
        expect(IgsnClassification::where('resource_id', $this->resource->id)->first()->value)->toBe('Existing');
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

    it('skips geological age if one already exists', function () {
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

        expect(IgsnGeologicalAge::where('resource_id', $this->resource->id)->count())->toBe(1);
        expect(IgsnGeologicalAge::where('resource_id', $this->resource->id)->first()->value)->toBe('Cretaceous');
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

    it('skips geological unit if one already exists', function () {
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

        expect(IgsnGeologicalUnit::where('resource_id', $this->resource->id)->count())->toBe(1);
        expect(IgsnGeologicalUnit::where('resource_id', $this->resource->id)->first()->value)->toBe('Existing Formation');
    });
});
