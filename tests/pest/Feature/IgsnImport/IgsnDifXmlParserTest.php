<?php

use App\Enums\AccessLevel;
use App\Enums\Igsn\IgsnClassificationType;
use App\Enums\Igsn\IgsnMeasurementType;
use App\Enums\Igsn\IgsnMetadataValueType;
use App\Models\Affiliation;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMeasurement;
use App\Models\IgsnMetadata;
use App\Models\IgsnMetadataValue;
use App\Models\IgsnMethod;
use App\Models\IgsnOperator;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\Size;
use App\Services\DataCiteJsonExporter;
use App\Services\IgsnDifXmlParser;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
    it('persists safe image source descriptors without publishing unvalidated external URLs', function () {
        $managed = <<<'XML'
        <resource><sample>
          <sample_image>SO273-31D-18_wet.jpg</sample_image>
          <sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path>
        </sample></resource>
        XML;

        expect($this->parser->enrichFromDifXml($managed, $this->resource, $this->igsnMetadata))->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->sample_image_source_url)
            ->toBe('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/SO273-31D-18_wet.jpg')
            ->and($this->igsnMetadata->sample_image_external_url)->toBeNull()
            ->and($this->igsnMetadata->sample_image_storage_path)->toBeNull();

        $external = <<<'XML'
        <resource><sample>
          <sample_image>CS_5054.jpg</sample_image>
          <sample_image_path>http://www-icdp.icdp-online.org/sites/cosc/news/cores/</sample_image_path>
        </sample></resource>
        XML;
        expect($this->parser->enrichFromDifXml($external, $this->resource, $this->igsnMetadata))->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->sample_image_source_url)
            ->toBe('http://www-icdp.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg')
            ->and($this->igsnMetadata->sample_image_external_url)->toBeNull();
    });

    it('keeps a matching managed image and clears it when the managed source changes', function () {
        Storage::fake('public');
        $oldPath = 'igsn-sample-images/gfso273n39/old.jpg';
        $oldSource = 'https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/old.jpg';
        $this->igsnMetadata->update([
            'sample_image_source_url' => $oldSource,
            'sample_image_storage_path' => $oldPath,
            'sample_image_mime_type' => 'image/jpeg',
            'sample_image_size' => 123,
        ]);
        Storage::disk('public')->put($oldPath, 'old image');

        $sameSource = <<<'XML'
        <resource><sample>
          <sample_image>old.jpg</sample_image>
          <sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path>
        </sample></resource>
        XML;
        expect($this->parser->enrichFromDifXml($sameSource, $this->resource, $this->igsnMetadata))->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->sample_image_storage_path)->toBe($oldPath);
        Storage::disk('public')->assertExists($oldPath);

        $changedSource = <<<'XML'
        <resource><sample>
          <sample_image>new.jpg</sample_image>
          <sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path>
        </sample></resource>
        XML;
        expect($this->parser->enrichFromDifXml($changedSource, $this->resource, $this->igsnMetadata))->toBeTrue();
        $this->igsnMetadata->refresh();

        expect($this->igsnMetadata->sample_image_source_url)
            ->toBe('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/new.jpg')
            ->and($this->igsnMetadata->sample_image_storage_path)->toBeNull()
            ->and($this->igsnMetadata->sample_image_mime_type)->toBeNull()
            ->and($this->igsnMetadata->sample_image_size)->toBeNull()
            ->and($this->igsnMetadata->sampleImageUrl())->toBeNull();
        Storage::disk('public')->assertMissing($oldPath);
    });

    it('parses scalar fields from DIF XML with namespace', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample xmlns="http://pmd.gfz-potsdam.de/igsn/schemas/description-ext/1.3">
                        <sample_type>Rock</sample_type>
                        <material>Rock</material>
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
        expect($this->igsnMetadata->material)->toBe('Rock');
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
                        <material>Sediment</material>
                    </sample>
                </record>
            </supplementalMetadata>
        </DIF>
        XML;

        $result = $this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata);

        expect($result)->toBeTrue();
        $this->igsnMetadata->refresh();
        expect($this->igsnMetadata->sample_type)->toBe('Sediment');
        expect($this->igsnMetadata->material)->toBe('Sediment');
    });

    it('persists material descriptions and explicit comments separately', function () {
        $this->igsnMetadata->update([
            'description_json' => ['comments' => ['Old incorrectly mapped description']],
        ]);

        $xml = <<<'XML'
        <resource>
            <description>Smell: None, sediment type: sandy</description>
            <sample>
                <material>Sediment</material>
                <descriptions>
                    <description>Smell: None, sediment type: sandy</description>
                </descriptions>
                <sample_comment>Stored frozen after collection</sample_comment>
            </sample>
        </resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        $descriptionJson = $this->igsnMetadata->fresh()->description_json;
        expect($descriptionJson['description_groups'])->toBe([['entries' => [[
            'value' => 'Smell: None, sediment type: sandy',
            'scheme' => null,
        ]]]])
            ->and($descriptionJson['material_descriptions'])->toBe(['Smell: None, sediment type: sandy'])
            ->and($descriptionJson['comments'])->toBe(['Stored frozen after collection']);
    });

    it('removes obsolete imported comments when a reimport has no explicit comment', function () {
        $this->igsnMetadata->update([
            'description_json' => [
                'parent_igsn_handle' => 'GFHER7EC99',
                'comments' => ['porewater,'],
            ],
        ]);

        $xml = <<<'XML'
        <resource>
            <description>porewater,</description>
            <sample>
                <material>Liquid&gt;aqueous</material>
                <descriptions><description>porewater,</description></descriptions>
            </sample>
        </resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        $descriptionJson = $this->igsnMetadata->fresh()->description_json;
        expect($descriptionJson['parent_igsn_handle'])->toBe('GFHER7EC99')
            ->and($descriptionJson['material_descriptions'])->toBe(['porewater,'])
            ->and($descriptionJson['description_groups'])->toBe([['entries' => [[
                'value' => 'porewater,',
                'scheme' => null,
            ]]]])
            ->and($descriptionJson)->not->toHaveKey('comments');
    });

    it('atomically replaces description groups while preserving unrelated JSON keys and remains idempotent', function (): void {
        $this->igsnMetadata->update([
            'description_json' => [
                'parent_igsn_handle' => 'GF-PARENT',
                'custom_key' => ['keep' => true],
                'description_groups' => [['entries' => [['value' => 'old', 'scheme' => null]]]],
            ],
        ]);
        $xml = <<<'XML'
        <resource><sample>
          <descriptions>
            <description>Value; stays whole</description>
            <description descriptionScheme="Rock Type">Quartzite</description>
          </descriptions>
        </sample></resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();
        $first = $this->igsnMetadata->fresh();
        $updatedAt = $first->updated_at;

        expect($first->description_json)->toMatchArray([
            'parent_igsn_handle' => 'GF-PARENT',
            'custom_key' => ['keep' => true],
            'description_groups' => [['entries' => [
                ['value' => 'Value; stays whole', 'scheme' => null],
                ['value' => 'Quartzite', 'scheme' => 'Rock Type'],
            ]]],
        ])->and($this->parser->enrichFromDifXml($xml, $this->resource, $first))->toBeTrue()
            ->and($this->igsnMetadata->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    });

    it('persists locality descriptions without overwriting an existing curated value', function (): void {
        $location = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'place' => 'Existing place',
            'locality_description' => 'Curated locality',
        ]);
        $xml = '<resource><sample><locality_description>Imported locality</locality_description></sample></resource>';

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue()
            ->and($location->fresh()->locality_description)->toBe('Curated locality');
    });

    it('keeps collection method and its description in separate columns', function () {
        $xml = <<<'XML'
        <resource><sample>
            <material>Sediment</material>
            <collection_method>unconsolidated sediment corers</collection_method>
            <collection_method_descr>Box corer</collection_method_descr>
        </sample></resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        $metadata = $this->igsnMetadata->fresh();
        expect($metadata->collection_method)->toBe('unconsolidated sediment corers')
            ->and($metadata->collection_method_description)->toBe('Box corer');
    });

    it('rejects unsupported controlled material values without persisting partial enrichment', function () {
        $xml = '<resource><sample><material>Granite</material></sample></resource>';

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeFalse()
            ->and($this->igsnMetadata->fresh()->material)->toBeNull();
    });

    it('logs and skips unsupported legacy classifications without losing valid DIF metadata', function () {
        Log::spy();

        $xml = <<<'XML'
        <resource><sample>
            <material>Rock</material>
            <classification>Igneous; legacy rock term</classification>
            <collection_method>Core drilling</collection_method>
        </sample></resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue()
            ->and($this->igsnMetadata->fresh()->collection_method)->toBe('Core drilling')
            ->and(IgsnClassification::where('resource_id', $this->resource->id)->pluck('value')->all())->toBe(['Igneous']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Skipped unsupported DIF classification', Mockery::on(
                fn (array $context): bool => $context['resource_id'] === $this->resource->id
                    && $context['material'] === 'Rock'
                    && $context['classification'] === 'legacy rock term'
                    && $context['sample_index'] === 0,
            ));
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
                        <material>Rock</material>
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
        expect($classification->classification_type)->toBe(IgsnClassificationType::ROCK);
    });

    it('maps unique classifications from every DIF record in source order', function () {
        $xml = <<<'XML'
        <DIF><supplementalMetadata>
          <record><sample><material>Rock</material><classification>fault related rocks</classification></sample></record>
          <record><sample><material>Rock</material><classification>FAULT RELATED ROCKS;metamorphic rocks</classification></sample></record>
          <record><sample><material>Biology</material><classification>vegetation:bark</classification></sample></record>
        </supplementalMetadata></DIF>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        $classifications = IgsnClassification::query()
            ->whereBelongsTo($this->resource)
            ->orderBy('position')
            ->get();

        expect($classifications->pluck('value')->all())->toBe([
            'fault related rocks',
            'metamorphic rocks',
            'vegetation:bark',
        ])->and($classifications->pluck('classification_type')->all())->toBe([
            IgsnClassificationType::ROCK,
            IgsnClassificationType::ROCK,
            IgsnClassificationType::BIOLOGY,
        ]);
    });

    it('skips classification when N/A', function () {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DIF>
            <supplementalMetadata>
                <record>
                    <sample>
                        <sample_type>Rock</sample_type>
                        <material>Rock</material>
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
                        <material>Rock</material>
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

    it('fills missing classification types without overwriting existing types', function () {
        $curatedClassification = IgsnClassification::create([
            'resource_id' => $this->resource->id,
            'value' => 'Igneous',
            'classification_type' => IgsnClassificationType::BIOLOGY,
            'position' => 0,
        ]);
        $untypedClassification = IgsnClassification::create([
            'resource_id' => $this->resource->id,
            'value' => 'metamorphic rocks',
            'position' => 1,
        ]);
        $originalTimestamp = now()->subDay()->startOfSecond();
        IgsnClassification::withoutTimestamps(function () use ($curatedClassification, $untypedClassification, $originalTimestamp): void {
            $curatedClassification->forceFill(['updated_at' => $originalTimestamp])->saveQuietly();
            $untypedClassification->forceFill(['updated_at' => $originalTimestamp])->saveQuietly();
        });

        $xml = <<<'XML'
        <DIF><sample>
            <material>Rock</material>
            <classification>Igneous;metamorphic rocks</classification>
        </sample></DIF>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue();

        $classifications = IgsnClassification::query()
            ->whereBelongsTo($this->resource)
            ->orderBy('position')
            ->get();

        expect($classifications)->toHaveCount(2)
            ->and($classifications->pluck('classification_type')->all())->toBe([
                IgsnClassificationType::BIOLOGY,
                IgsnClassificationType::ROCK,
            ])
            ->and($classifications[0]->updated_at?->equalTo($originalTimestamp))->toBeTrue()
            ->and($classifications[1]->updated_at?->greaterThan($originalTimestamp))->toBeTrue();
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
            ->and($meta->description_json['material_descriptions'])->toBe(['Granodiorite'])
            ->and($meta->description_json)->not->toHaveKey('comments')
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
            'material_descriptions' => ['Granodiorite'],
            'comments' => [],
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

    it('persists issue 1225 and report metadata idempotently in standard and legacy storage', function () {
        $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
        $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);

        $xml = <<<'XML'
        <resource xmlns="http://pmd.gfz-potsdam.de/igsn/schemas/description/1.3">
          <relatedIdentifiers>
            <relatedIdentifier type="DOI" relationType="hasDocument">https://doi.org/10.2204/iodp.sd.8.12.2009</relatedIdentifier>
            <relatedIdentifier type="DOI" relationType="hasDocument">10.5880/ICDP.5052.001</relatedIdentifier>
          </relatedIdentifiers>
          <contributors>
            <contributor type="Other"><name>Institute of Geological and Nuclear Sciences Limited (GNS)</name></contributor>
            <contributor type="Funder"><name>Marsden Fund, International Continental Scientific Drilling Program</name></contributor>
          </contributors>
          <supplementalMetadata><record><sample>
            <material>Rock</material>
            <field_name>Torlesse Greywacke</field_name>
            <geological_age>quaternary-cretaceous-carboniferous</geological_age>
            <methods><method methodScheme="MSCL">no</method></methods>
            <publish_date>2017-3-1</publish_date>
            <sampling_date>2014-12-14T18:30:00Z</sampling_date>
            <length>893.2</length><length_unit>m</length_unit>
            <relatedIdentifiers>
              <relatedIdentifier relatedIdentifierType="DOI" relationType="IsCitedBy">10.2204/iodp.sd.8.12.2009</relatedIdentifier>
            </relatedIdentifiers>
          </sample></record></supplementalMetadata>
        </resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata))->toBeTrue()
            ->and($this->parser->enrichFromDifXml($xml, $this->resource->fresh(), $this->igsnMetadata->fresh(), additive: true))->toBeTrue();

        $relations = RelatedIdentifier::query()->whereBelongsTo($this->resource)->with(['identifierType', 'relationType'])->get();
        expect($relations)->toHaveCount(2)
            ->and($relations->pluck('identifier')->all())->toBe(['10.2204/iodp.sd.8.12.2009', '10.5880/ICDP.5052.001'])
            ->and($relations->every(fn (RelatedIdentifier $relation): bool => $relation->identifierType->slug === 'DOI'
                && $relation->relationType->slug === 'Cites'
                && $relation->source === RelatedIdentifier::SOURCE_LEGACY_IGSN_DIF))->toBeTrue()
            ->and(FundingReference::query()->whereBelongsTo($this->resource)->sole()->funder_name)
            ->toBe('Marsden Fund, International Continental Scientific Drilling Program');

        $dates = ResourceDate::query()->whereBelongsTo($this->resource)->with('dateType')->get()->keyBy('dateType.slug');
        expect($dates['Available']->date_value)->toBe('2017-03-01')
            ->and($dates['Collected']->date_value)->toBe('2014-12-14T18:30:00Z')
            ->and(IgsnGeologicalAge::query()->whereBelongsTo($this->resource)->sole()->value)
            ->toBe('quaternary-cretaceous-carboniferous');

        expect(IgsnMetadataValue::query()->whereBelongsTo($this->resource)->sole()->value)
            ->toBe('Torlesse Greywacke')
            ->and(IgsnMethod::query()->whereBelongsTo($this->resource)->sole()->only(['scheme', 'value']))
            ->toBe(['scheme' => 'MSCL', 'value' => 'no'])
            ->and(IgsnMeasurement::query()->whereBelongsTo($this->resource)->sole()->only(['start_value', 'unit']))
            ->toBe(['start_value' => '893.2', 'unit' => 'm'])
            ->and(IgsnOperator::query()->whereBelongsTo($this->resource)->sole()->value)
            ->toBe('Institute of Geological and Nuclear Sciences Limited (GNS)')
            ->and($this->resource->sizes()->doesntExist())->toBeTrue();

        $attributes = (new DataCiteJsonExporter)->export($this->resource->fresh())['data']['attributes'];
        expect($attributes['relatedIdentifiers'])->toContain(
            [
                'relatedIdentifier' => '10.2204/iodp.sd.8.12.2009',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
            [
                'relatedIdentifier' => '10.5880/ICDP.5052.001',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
        )->and($attributes['fundingReferences'])->toContain([
            'funderName' => 'Marsden Fund, International Continental Scientific Drilling Program',
        ])->and($attributes['dates'])->toContain(
            ['dateType' => 'Available', 'date' => '2017-03-01', 'dateInformation' => 'Legacy IGSN publish date'],
            ['dateType' => 'Collected', 'date' => '2014-12-14T18:30:00Z', 'dateInformation' => 'Legacy IGSN sampling date'],
        )->and($attributes)->not->toHaveKey('sizes');
    });

    it('keeps an existing scalar during additive backfill while retaining the DIF source value', function () {
        $this->igsnMetadata->update(['operator' => 'Curated operator']);

        expect($this->parser->enrichFromDifXml(
            '<resource><sample><operators><operator>Legacy operator</operator></operators></sample></resource>',
            $this->resource,
            $this->igsnMetadata,
            additive: true,
        ))->toBeTrue();

        $metadata = $this->igsnMetadata->fresh();
        expect($metadata->operator)->toBe('Curated operator')
            ->and(IgsnOperator::query()->whereBelongsTo($this->resource)->orderBy('position')->pluck('value')->all())
            ->toBe(['Curated operator', 'Legacy operator']);
    });

    it('persists issue 1226 and 1227 metadata with supplemental precedence and typed cardinalities', function () {
        $xml = <<<'XML'
        <resource>
          <contributors>
            <contributor type="Other"><name>Root operator</name></contributor>
            <contributor type="Funder"><name>Root funder</name></contributor>
            <contributor contributorType="ProjectLeader">
              <name>Doe, Jane</name>
              <affiliation><name>GFZ Potsdam</name></affiliation>
              <identifier>https://orcid.org/0000-0002-1825-0097</identifier>
            </contributor>
          </contributors>
          <sample>
            <operators><operator>Operator A</operator><operator>Operator B</operator></operators>
            <funding_agency>Funding Agency A, Program B</funding_agency>
            <geological_age>Jurassic</geological_age>
            <classification_comment>Reviewed classification</classification_comment>
            <sample_request>Request 42</sample_request>
            <sampled_by>Requester A</sampled_by>
            <methods><method methodScheme="XRF">Method A</method></methods>
            <length>25.5</length><length_unit>m</length_unit>
            <age_min>10</age_min><age_max>20</age_max><age_unit>Ma</age_unit>
            <elevation>100</elevation><elevation_end>110</elevation_end>
            <elevation_unit>m</elevation_unit><elevation_end_unit>m</elevation_end_unit>
            <launch_platform_name>SO-273</launch_platform_name>
            <launch_type_name>Piston corer</launch_type_name>
            <navigation_type>GPS</navigation_type>
          </sample>
        </resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata, additive: true))->toBeTrue();

        expect(IgsnOperator::query()->whereBelongsTo($this->resource)->orderBy('position')->pluck('value')->all())
            ->toBe(['Operator A', 'Operator B'])
            ->and($this->igsnMetadata->fresh()->operator)->toBeNull()
            ->and($this->resource->fundingReferences()->sole()->funder_name)->toBe('Funding Agency A, Program B')
            ->and($this->resource->igsnGeologicalAges()->sole()->value)->toBe('Jurassic')
            ->and($this->resource->igsnMethods()->sole()->only(['scheme', 'value']))
            ->toBe(['scheme' => 'XRF', 'value' => 'Method A'])
            ->and($this->resource->igsnMeasurements()->get()->groupBy(fn ($item) => $item->type->value)->keys()->all())
            ->toBe([
                IgsnMeasurementType::AgeRange->value,
                IgsnMeasurementType::ElevationRange->value,
                IgsnMeasurementType::TotalLength->value,
            ])
            ->and($this->resource->igsnMetadataValues()->get()->groupBy(fn ($item) => $item->type->value)->keys()->sort()->values()->all())
            ->toBe(collect([
                IgsnMetadataValueType::ClassificationComment->value,
                IgsnMetadataValueType::SampleRequest->value,
                IgsnMetadataValueType::SampledBy->value,
                IgsnMetadataValueType::LaunchPlatformName->value,
                IgsnMetadataValueType::LaunchTypeName->value,
                IgsnMetadataValueType::NavigationType->value,
            ])->sort()->values()->all());

        $leader = $this->resource->contributors()->with(['contributorable', 'contributorTypes'])->sole();
        expect($leader->contributorable)->toBeInstanceOf(Person::class)
            ->and($leader->contributorable->name_identifier)->toBe('https://orcid.org/0000-0002-1825-0097')
            ->and($leader->contributorTypes->sole()->slug)->toBe('ProjectLeader')
            ->and($leader->affiliations()->sole()->name)->toBe('GFZ Potsdam');
    });

    it('rejects a correctly shaped project leader ORCID with an invalid checksum', function () {
        $xml = <<<'XML'
        <resource>
          <contributors>
            <contributor contributorType="ProjectLeader">
              <name>Doe, Jane</name>
              <identifier>https://orcid.org/0000-0002-1825-0098</identifier>
            </contributor>
          </contributors>
          <sample><field_name>ORCID checksum regression fixture</field_name></sample>
        </resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata, additive: true))->toBeTrue();

        $leader = $this->resource->contributors()->with('contributorable')->sole();
        expect($leader->contributorable)->toBeInstanceOf(Person::class)
            ->and($leader->contributorable->name_identifier)->toBeNull()
            ->and($leader->contributorable->name_identifier_scheme)->toBeNull()
            ->and($leader->contributorable->scheme_uri)->toBeNull();
    });

    it('normalizes contributor affiliations and avoids whitespace duplicates', function () {
        $collectorType = ContributorType::query()->where('slug', 'DataCollector')->firstOrFail();
        $leaderType = ContributorType::query()->where('slug', 'ProjectLeader')->firstOrFail();

        $collector = Person::create(['family_name' => 'Roe', 'given_name' => 'Richard']);
        $collectorRelation = ResourceContributor::create([
            'resource_id' => $this->resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $collector->id,
            'position' => 0,
        ]);
        $collectorRelation->contributorTypes()->attach($collectorType);
        $collectorRelation->affiliations()->create(['name' => ' ICDP Operations ']);

        $leader = Person::create(['family_name' => 'Doe', 'given_name' => 'Jane']);
        $leaderRelation = ResourceContributor::create([
            'resource_id' => $this->resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $leader->id,
            'position' => 1,
        ]);
        $leaderRelation->contributorTypes()->attach($leaderType);
        $leaderRelation->affiliations()->create(['name' => ' GFZ ']);

        $xml = <<<'XML'
        <resource>
          <contributors>
            <contributor contributorType="ProjectLeader">
              <name>Doe, Jane</name>
              <affiliation><name>GFZ</name></affiliation>
              <affiliation><name>  University   of Potsdam  </name></affiliation>
              <affiliation><name>   </name></affiliation>
            </contributor>
          </contributors>
          <sample>
            <collector>Roe, Richard</collector>
            <collector_detail>ICDP Operations</collector_detail>
          </sample>
        </resource>
        XML;

        expect($this->parser->enrichFromDifXml($xml, $this->resource, $this->igsnMetadata, additive: true))->toBeTrue();

        expect($collectorRelation->affiliations()->pluck('name')->all())
            ->toBe([' ICDP Operations '])
            ->and($leaderRelation->affiliations()->orderBy('id')->pluck('name')->all())
            ->toBe([' GFZ ', 'University of Potsdam']);
    });
});
