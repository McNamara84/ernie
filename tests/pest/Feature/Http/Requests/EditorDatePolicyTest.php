<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Services\DataCite\DataCiteDateFormatService;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Editor\EditorDataTransformer;
use Database\Seeders\DateTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->travelTo('2026-11-20 12:00:00');
    Http::preventStrayRequests();
    $this->seed(DateTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'MainTitle']);
    DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract']);
    $this->datePayload = [
        'year' => 2019,
        'resourceType' => ResourceType::factory()->create()->id,
        'accessLevel' => 'open',
        'titles' => [['title' => 'Historical and projected dataset dates', 'titleType' => 'main-title']],
        'licenses' => [Right::factory()->create()->identifier],
        'authors' => [['type' => 'person', 'position' => 0, 'firstName' => 'Example', 'lastName' => 'Author', 'isContact' => false, 'affiliations' => []]],
        'descriptions' => [['descriptionType' => 'abstract', 'description' => 'A dataset with historical observations and future projections.']],
        'datacenter_id' => Datacenter::create(['name' => 'Date validation tests'])->id,
    ];
});

dataset('editor date save endpoints', ['/editor/resources', '/editor/resources/draft']);

it('saves, reopens and exports the five reported date rows without losing precision', function (string $endpoint): void {
    $dates = [
        ['dateType' => 'other', 'dateMode' => 'range', 'startDate' => '2006', 'endDate' => '2099'],
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '2100', 'endDate' => '2299'],
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '1861', 'endDate' => '2005'],
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '1661', 'endDate' => '1860'],
        ['dateType' => 'created', 'dateMode' => 'single', 'startDate' => '2017', 'endDate' => null],
    ];
    $saved = $this->postJson($endpoint, [...$this->datePayload, 'dates' => $dates])->assertSuccessful();
    $resource = Resource::findOrFail($saved->json('resource.id'));
    expect($resource->access_level)->toBe(AccessLevel::OPEN);

    $this->postJson($endpoint, [...$this->datePayload, 'resourceId' => $resource->id, 'dates' => $dates])->assertSuccessful();
    $reopened = app(EditorDataTransformer::class)->transformDates($resource->fresh()->load('dates.dateType'));
    foreach ($dates as $date) {
        $matching = collect($reopened)->first(fn (array $row): bool => strtolower($row['dateType']) === $date['dateType']
            && $row['startDate'] === $date['startDate'] && $row['endDate'] === ($date['endDate'] ?? ''));
        expect($matching)->not->toBeNull()
            ->and($matching['dateMode'])->toBe($date['dateMode']);
    }

    $resource->update(['doi' => '10.5880/test-dates-validation']);
    $attributes = app(DataCiteJsonExporter::class)->export($resource->fresh())['data']['attributes'];
    $document = new DOMDocument;
    expect($document->loadXML(app(DataCiteXmlExporter::class)->export($resource->fresh())))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('d', 'http://datacite.org/schema/kernel-4');
    foreach ($dates as $date) {
        $value = $date['startDate'].($date['endDate'] ? '/'.$date['endDate'] : '');
        $type = ucfirst($date['dateType']);
        expect(DataCiteDateFormatService::isDate($value))->toBeTrue()
            ->and($attributes['dates'])->toContain(['dateType' => $type, 'date' => $value])
            ->and($xpath->query('/d:resource/d:dates/d:date[@dateType="'.$type.'"][text()="'.$value.'"]'))->toHaveCount(1);
    }
    expect($document->schemaValidate(resource_path('data/scheme/datacite-4.7/metadata.xsd')))->toBeTrue();
})->with('editor date save endpoints');

it('preserves imported historical and projected XML and JSON dates through saving and export', function (string $format): void {
    Storage::fake('local');
    $sourceDates = [
        ['dateType' => 'Other', 'date' => '2006/2099'],
        ['dateType' => 'Collected', 'date' => '2100/2299'],
        ['dateType' => 'Collected', 'date' => '1861/2005'],
        ['dateType' => 'Collected', 'date' => '1661/1860'],
        ['dateType' => 'Created', 'date' => '2017'],
        ['dateType' => 'Collected', 'date' => '0001-01-01/0099-12'],
    ];
    if ($format === 'json') {
        $contents = json_encode(['data' => ['attributes' => [
            'doi' => '10.5880/test-dates-import',
            'creators' => [['name' => 'Example, Author', 'nameType' => 'Personal']],
            'titles' => [['title' => 'Historical and projected dates import']],
            'publisher' => ['name' => 'GFZ Data Services'],
            'publicationYear' => 2019,
            'types' => ['resourceTypeGeneral' => 'Dataset'],
            'dates' => $sourceDates,
        ]]], JSON_THROW_ON_ERROR);
    } else {
        $elements = implode('', array_map(fn (array $date): string => '<date dateType="'.$date['dateType'].'">'.$date['date'].'</date>', $sourceDates));
        $contents = '<resource xmlns="http://datacite.org/schema/kernel-4">'
            .'<identifier identifierType="DOI">10.5880/test-dates-import</identifier>'
            .'<creators><creator><creatorName>Example, Author</creatorName></creator></creators>'
            .'<titles><title>Historical and projected dates import</title></titles>'
            .'<publisher>GFZ Data Services</publisher><publicationYear>2019</publicationYear>'
            .'<resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>'
            .'<dates>'.$elements.'</dates></resource>';
    }
    $metadata = $this->postJson('/editor/upload-'.$format.'/preview', [
        'file' => UploadedFile::fake()->createWithContent('dates.'.$format, $contents),
    ])->assertOk()->json('metadata');
    $saved = $this->postJson('/editor/resources', [...$this->datePayload, 'dates' => $metadata['dates']])->assertSuccessful();
    $resource = Resource::findOrFail($saved->json('resource.id'));
    $reopened = app(EditorDataTransformer::class)->transformDates($resource->load('dates.dateType'));
    $exported = app(DataCiteJsonExporter::class)->export($resource)['data']['attributes']['dates'];
    foreach ($sourceDates as $date) {
        [$start, $end] = array_pad(explode('/', $date['date'], 2), 2, '');
        expect(collect($reopened)->contains(fn (array $row): bool => strtolower($row['dateType']) === strtolower($date['dateType'])
            && $row['startDate'] === $start && $row['endDate'] === $end))->toBeTrue()
            ->and($exported)->toContain($date);
    }
    Http::assertNothingSent();
})->with(['xml', 'json']);

it('preserves early and late years, calendar dates and timestamps through storage and reopening', function (string $endpoint): void {
    foreach (['0001', '0001-01', '0001-01-01', '0001-01-01T00:00:00Z', '0004-02-29', '0099-12-31', '0099-12-31T23:59:59+02:00', '1661-03-01T12:30:45+02:00', '9999', '9999-12', '9999-12-31T23:59:59Z'] as $value) {
        $saved = $this->postJson($endpoint, [
            ...$this->datePayload,
            'dates' => [['dateType' => 'collected', 'dateMode' => 'single', 'startDate' => $value]],
        ])->assertSuccessful();
        $resource = Resource::findOrFail($saved->json('resource.id'));
        $date = $resource->dates()->whereHas('dateType', fn ($query) => $query->where('slug', 'Collected'))->sole();
        expect($date->date_value)->toBe($value)->and($resource->access_level)->toBe(AccessLevel::OPEN);
        $reopened = app(EditorDataTransformer::class)->transformDates($resource->load('dates.dateType'));
        expect(collect($reopened)->first(fn (array $row): bool => strtolower($row['dateType']) === 'collected')['startDate'])->toBe($value);
    }
})->with('editor date save endpoints');

it('allows future single dates and both range endpoints only for projection-capable types', function (string $endpoint): void {
    foreach (['collected', 'Collected', 'valid', 'Valid', 'other', 'Other'] as $type) {
        foreach ([
            ['dateType' => $type, 'dateMode' => 'single', 'startDate' => '2299-06'],
            ['dateType' => $type, 'dateMode' => 'range', 'startDate' => '2100', 'endDate' => '2299'],
        ] as $date) {
            $saved = $this->postJson($endpoint, [...$this->datePayload, 'dates' => [$date]])->assertSuccessful();
            expect(Resource::findOrFail($saved->json('resource.id'))->access_level)->toBe(AccessLevel::OPEN);
        }
    }
    foreach (['created', 'copyrighted', 'submitted', 'withdrawn'] as $type) {
        $this->postJson($endpoint, [
            ...$this->datePayload,
            'dates' => [['dateType' => $type, 'dateMode' => 'single', 'startDate' => '2299']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['dates.0.startDate']);
    }
    $this->postJson($endpoint, [
        ...$this->datePayload,
        'dates' => [['dateType' => 'created', 'dateMode' => 'range', 'startDate' => '2017', 'endDate' => '2299']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['dates.0.endDate'])->assertJsonMissingValidationErrors(['dates.0.startDate']);
})->with('editor date save endpoints');

it('rejects invalid date endpoints without adding misleading future errors', function (string $endpoint): void {
    foreach (['0000', '0000-01', '0000-01-01', '-0054', '10000', '10000-01-01', '0100-02-29', '1700-02-29', '9999-02-30', 'zzzz', '0000-01-01T12:30Z'] as $value) {
        foreach (['startDate', 'endDate'] as $field) {
            $date = ['dateType' => 'created', 'dateMode' => 'range', 'startDate' => '0001', 'endDate' => '2020'];
            $date[$field] = $value;
            $response = $this->postJson($endpoint, [...$this->datePayload, 'dates' => [$date]])
                ->assertUnprocessable()->assertJsonValidationErrors(['dates.0.'.$field]);
            expect(implode(' ', $response->json('errors')['dates.0.'.$field]))->not->toContain('future');
        }
    }
})->with('editor date save endpoints');

it('preserves the existing Available embargo rules for draft and final saves', function (string $endpoint): void {
    $saved = $this->postJson($endpoint, [
        ...$this->datePayload,
        'dates' => [['dateType' => 'available', 'dateMode' => 'single', 'startDate' => '2299-01-01']],
    ])->assertSuccessful();
    expect(Resource::findOrFail($saved->json('resource.id'))->access_level)->toBe(AccessLevel::EMBARGOED);

    foreach (['2299', '2299-01', '2299-01-01T12:00:00Z'] as $value) {
        $response = $this->postJson($endpoint, [
            ...$this->datePayload, 'accessLevel' => 'embargoed',
            'dates' => [['dateType' => 'available', 'dateMode' => 'single', 'startDate' => $value]],
        ]);
        if ($endpoint === '/editor/resources/draft') {
            $response->assertSuccessful();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors(['accessLevel']);
        }
    }
})->with('editor date save endpoints');
