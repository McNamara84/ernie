<?php

declare(strict_types=1);

use App\Exceptions\JsonLdConversionException;
use App\Models\IgsnMetadata;
use App\Models\RelatedItem;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteJsonImportNormalizerService;
use App\Services\DataCiteJsonLdContextService;
use App\Services\DataCiteJsonLdToJsonConverterService;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\JsonSchemaValidator;
use App\Services\SchemaOrgJsonLdExporter;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;
use Tests\Fixtures\SchemaOrgExampleMetadata;
use Tests\Fixtures\SchemaOrgResourceTypes;

test('preserves the complete DataCite profile and produces independent semantic test artifacts', function () {
    $attributes = json_decode(file_get_contents(base_path('tests/pest/Fixtures/JsonLd/datacite-attributes.json')), true, flags: JSON_THROW_ON_ERROR);
    $exporter = new DataCiteLinkedDataExporter;
    $converter = new DataCiteJsonLdToJsonConverterService;
    $normalizer = new DataCiteJsonImportNormalizerService;
    $validator = new JsonSchemaValidator;
    $variants = ['complete' => $attributes];
    $draft = $attributes;
    unset($draft['doi']);
    $variants['draft'] = $draft;
    foreach (['Poster', 'Presentation', 'PhysicalObject'] as $type) {
        $variant = $attributes;
        $variant['types']['resourceTypeGeneral'] = $type;
        $variants[$type] = $variant;
    }
    $single = $attributes;
    foreach (['creators', 'titles', 'subjects', 'relatedIdentifiers', 'sizes', 'formats', 'descriptions', 'geoLocations'] as $collection) {
        $single[$collection] = [$single[$collection][0]];
    }
    $variants['single'] = $single;
    $variants['empty optional attributes'] = [
        ...$single,
        'creators' => [['name' => 'Example Observatory']],
        'types' => ['resourceTypeGeneral' => 'Dataset', 'resourceType' => ''],
        'publisher' => ['name' => 'Example Publisher'],
        'titles' => [['title' => 'Research observations']],
        'subjects' => [['subject' => 'Free keyword']],
        'contributors' => [],
    ];
    unset($variants['empty optional attributes']['contributors']);
    $zero = $attributes;
    $zero['version'] = '0';
    $zero['subjects'][0]['classificationCode'] = '0';
    $zero['dates'][0]['dateInformation'] = '0';
    $zero['relatedIdentifiers'][0]['relationTypeInformation'] = '0';
    $zero['relatedItems'][0]['relationTypeInformation'] = '0';
    $variants['literal zero attributes'] = $zero;
    $report = ['profiles' => [], 'resources' => []];

    foreach ($variants as $name => $expected) {
        $document = $exporter->exportAttributes($expected);
        app(DataCiteJsonLdContextService::class)->assertSupportedDocument($document);
        $roundTrip = $normalizer->normalize($converter->convert($document));
        // The normalizer restores numeric coordinates and DataCite's related-item year type.
        expect($roundTrip)->toEqual($normalizer->normalize($expected));
        $errors = null;
        expect($validator->isValid($roundTrip, $errors, strictMode: $name !== 'draft'))->toBeTrue(json_encode($errors));
        $report['profiles'][] = ['name' => $name, 'attributes' => $expected, 'document' => $document];
    }

    $document = $exporter->exportAttributes($attributes);
    $replaceAliases = function (array $node) use (&$replaceAliases): array {
        $result = [];
        foreach ($node as $key => $value) {
            $key = ['schemeUri' => 'schemeURI', 'rightsUri' => 'rightsURI', 'valueUri' => 'valueURI', 'awardUri' => 'awardURI'][$key] ?? $key;
            $result[$key] = is_array($value) ? $replaceAliases($value) : $value;
        }

        return $result;
    };
    $aliases = $replaceAliases($document);
    app(DataCiteJsonLdContextService::class)->assertSupportedDocument($aliases);
    expect($normalizer->normalize($converter->convert($aliases)))->toEqual($normalizer->normalize($attributes));
    $report['profiles'][] = ['name' => 'URI aliases', 'attributes' => $attributes, 'document' => $aliases];
    $conflicting = $document;
    $conflicting['publisher']['attrs']['schemeURI'] = 'https://conflicting.example.org';
    expect(fn () => $converter->convert($conflicting))->toThrow(JsonLdConversionException::class, 'Conflicting');

    $this->seed([ResourceTypeSeeder::class, TitleTypeSeeder::class]);
    $titleType = TitleType::where('slug', 'MainTitle')->firstOrFail();
    foreach (SchemaOrgResourceTypes::cases() as $slug => [, $schemaType]) {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/jsonld.'.$slug,
            'resource_type_id' => ResourceType::where('slug', $slug)->firstOrFail()->id,
            'publication_year' => 2026,
        ]);
        $resource->titles()->create(['value' => 'Research observations', 'title_type_id' => $titleType->id]);
        SchemaOrgExampleMetadata::addTo($resource);
        if ($slug === 'physical-object') {
            IgsnMetadata::create(['resource_id' => $resource->id, 'sample_type' => 'Rock', 'material' => 'Granite', 'upload_status' => 'pending']);
        }
        $other = RelationType::firstOrCreate(['slug' => 'Other'], ['name' => 'Other', 'is_active' => true]);
        $presentation = RelatedItem::factory()->create([
            'resource_id' => $resource->id, 'related_item_type' => 'Presentation',
            'relation_type_id' => $other->id, 'relation_type_information' => 'Conference presentation',
        ]);
        $presentation->titles()->create(['title' => 'Observation presentation', 'title_type' => 'MainTitle', 'position' => 0]);
        $resource = $resource->fresh();
        $canonical = (new DataCiteJsonExporter)->export($resource, serializeDescriptionsForDataCite: false)['data']['attributes'];
        $document = $exporter->export($resource);
        expect($normalizer->normalize($converter->convert($document)))->toEqual($normalizer->normalize($canonical));
        expect($validator->validate($canonical, strictMode: true))->toBeTrue();
        $xml = new DOMDocument;
        expect($xml->loadXML((new DataCiteXmlExporter)->export($resource)))->toBeTrue()
            ->and($xml->schemaValidate(base_path('resources/data/scheme/datacite-4.7/metadata.xsd')))->toBeTrue();
        $schemaOrg = app(SchemaOrgJsonLdExporter::class)->export($resource, content: [
            'mimeType' => 'text/csv',
            'contentLinks' => [['url' => 'https://example.org/data.csv', 'mimeType' => 'text/csv', 'contentSize' => '12 MB']],
            'repositories' => [],
        ]);
        expect((array) $schemaOrg['@type'])->toContain($schemaType);
        $report['resources'][] = ['name' => $slug, 'attributes' => $canonical, 'document' => $document, 'schemaOrg' => $schemaOrg, 'expectedType' => $schemaType];
    }

    $token = getenv('ERNIE_JSONLD_REPORT_TOKEN');
    if ($token !== false && $token !== '') {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new RuntimeException('Invalid JSON-LD report token.');
        }
        file_put_contents(storage_path('framework/testing/jsonld-'.$token.'.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
});
