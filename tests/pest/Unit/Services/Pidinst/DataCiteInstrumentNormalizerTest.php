<?php

declare(strict_types=1);

use App\Services\Pidinst\DataCiteInstrumentNormalizer;
use Tests\Fixtures\DataCiteInstrumentFixture as Fixture;

covers(DataCiteInstrumentNormalizer::class);

it('maps the PIDINST fields and preserves unstructured technical information', function (): void {
    $record = Fixture::record('10.1234/SENSOR', [
        'titles' => [['title' => 'Alternate', 'titleType' => 'AlternateTitle'], ['title' => ' Main sensor ']],
        'descriptions' => [
            ['descriptionType' => 'TechnicalInfo', 'description' => ' Model: X; measures temperature '],
            ['descriptionType' => 'Abstract', 'description' => ' Main description '],
            ['descriptionType' => 'Other', 'description' => 'Not instrument detail'],
        ],
        'contributors' => [['name' => 'Not the owner', 'contributorType' => 'ContactPerson'], ['name' => 'Owner', 'contributorType' => 'HostingInstitution']],
    ]);
    expect(app(DataCiteInstrumentNormalizer::class)->normalize($record))->toBe([
        'id' => 'datacite:10.1234/sensor', 'pid' => '10.1234/sensor', 'pidType' => 'DOI', 'name' => 'Main sensor',
        'description' => "Main description\n\nModel: X; measures temperature", 'landingPage' => 'https://instruments.example.test/datacite',
        'owners' => ['Owner'], 'manufacturers' => ['Sensor manufacturer'], 'model' => null, 'instrumentTypes' => [], 'measuredVariables' => [],
    ]);
});

it('uses non-main titles and accepts absent optional fields', function (): void {
    $record = Fixture::record(attributes: ['titles' => [['title' => ' ', 'titleType' => 'Other'], ['title' => 'Other title', 'titleType' => 'Other']]]);
    foreach (['url', 'descriptions', 'creators', 'contributors'] as $field) {
        unset($record['attributes'][$field]);
    }
    expect(app(DataCiteInstrumentNormalizer::class)->normalize($record))->toMatchArray([
        'name' => 'Other title', 'landingPage' => '', 'description' => '', 'owners' => [], 'manufacturers' => [],
    ]);
});

it('normalizes DOI resolver forms without altering the suffix meaning', function (string $doi): void {
    expect(DataCiteInstrumentNormalizer::doi($doi))->toBe('10.1234/sensor');
})->with(['10.1234/SENSOR', ' https://doi.org/10.1234/Sensor ', 'http://dx.doi.org/10.1234/SENSOR']);

it('rejects invalid identifiers', function (mixed $doi): void {
    expect(fn () => DataCiteInstrumentNormalizer::doi($doi))->toThrow(InvalidArgumentException::class);
})->with([null, 5, '', 'doi:10.1234/sensor', '10.12/no', '10.1234/has space', 'https://other.test/10.1234/sensor']);

it('rejects malformed records without making up metadata', function (string $field, mixed $value): void {
    $record = Fixture::record();
    data_set($record, $field, $value);
    expect(fn () => app(DataCiteInstrumentNormalizer::class)->normalize($record))->toThrow(InvalidArgumentException::class);
})->with([
    ['id', '10.1234/other'], ['attributes.state', 'draft'], ['attributes.types.resourceTypeGeneral', 'Dataset'],
    ['attributes.titles', []], ['attributes.titles', 'bad'], ['attributes.titles', ['bad']],
    ['attributes.titles', [['title' => ' ']]], ['attributes.titles', [['title' => 5]]],
    ['attributes.url', 'javascript:alert(1)'], ['attributes.url', 1],
    ['attributes.creators', 'bad'], ['attributes.creators', [['name' => null]]],
    ['attributes.contributors', [['contributorType' => 'HostingInstitution']]], ['attributes.descriptions', [['description' => 5]]],
]);

it('retains instruments with empty optional description placeholders returned by the public API', function (): void {
    $record = Fixture::record(attributes: ['descriptions' => [
        ['descriptionType' => 'Abstract'], ['descriptionType' => 'TechnicalInfo', 'description' => null],
        ['descriptionType' => 'Abstract', 'description' => ' '],
    ]]);
    expect(app(DataCiteInstrumentNormalizer::class)->normalize($record))->toMatchArray(['name' => 'DataCite sensor', 'description' => '']);
});
