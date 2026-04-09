<?php

use App\Models\GeoLocation;
use App\Models\IgsnMetadata;
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
});
