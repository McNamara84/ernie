<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\Description;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\Title;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteXmlExporter;
use App\Services\EmbargoService;
use App\Services\JsonSchemaValidator;
use App\Services\ResourceStorageService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function embargoResource(string $date, bool $withPage = true): Resource
{
    $resource = Resource::factory()->create(['doi' => null, 'access_level' => AccessLevel::EMBARGOED]);
    Title::factory()->create(['resource_id' => $resource->id]);
    ResourceCreator::factory()->create(['resource_id' => $resource->id]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    $resource->rights()->attach(Right::factory()->create());
    $type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $date]);
    if ($withPage) {
        LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'template' => 'default_gfz']);
    }

    return $resource->fresh(['dates.dateType', 'landingPage', 'titles.titleType', 'creators', 'rights', 'descriptions.descriptionType']);
}

beforeEach(function (): void {
    config([
        'app.timezone' => 'Europe/Berlin',
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
    ]);
});

test('embargo remains a workflow state across midnight and becomes due at the local day boundary', function (): void {
    $resource = embargoResource('2027-01-01');
    $policy = app(EmbargoService::class);

    $this->travelTo(Carbon::parse('2026-12-31 23:59:59', 'Europe/Berlin'));
    expect($resource->publicStatus())->toBe('embargo')
        ->and($policy->isDue($resource))->toBeFalse();

    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));
    expect($resource->publicStatus())->toBe('embargo')
        ->and($policy->isDue($resource))->toBeTrue();
    $policy->assertCanRegister($resource);
});

test('a leap-day Available date becomes due at local midnight', function (): void {
    $resource = embargoResource('2028-02-29');
    $policy = app(EmbargoService::class);
    expect($policy->availableDate($resource))->toBe('2028-02-29');

    $this->travelTo(Carbon::parse('2028-02-28 23:59:59', 'Europe/Berlin'));
    expect($policy->isDue($resource))->toBeFalse();
    $this->travelTo(Carbon::parse('2028-02-29 00:00:00', 'Europe/Berlin'));
    expect($policy->isDue($resource))->toBeTrue();
});

test('Available without Embargoed access is not an embargo workflow', function (): void {
    $resource = embargoResource('2027-01-01');
    $resource->access_level = AccessLevel::OPEN;
    $resource->save();
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));

    expect(app(EmbargoService::class)->isDue($resource))->toBeFalse()
        ->and($resource->publicStatus())->not->toBe('embargo');
});

test('invalid and ambiguous Available dates fail closed', function (string $date): void {
    $resource = embargoResource($date);
    $policy = app(EmbargoService::class);
    expect($policy->availableDate($resource))->toBeNull()
        ->and($resource->isComplete())->toBeFalse();
    expect(fn () => $policy->assertCanRegister($resource))
        ->toThrow(InvalidArgumentException::class, 'exactly one valid day-precision Available date');
})->with(['2027', '2027-02', '2027-02-29', '2027-01-01T00:00:00']);

test('multiple Available dates fail closed', function (): void {
    $resource = embargoResource('2027-01-01');
    $resource->dates()->create([
        'date_type_id' => $resource->dates->first()->date_type_id,
        'date_value' => '2027-02-01',
    ]);
    $resource->unsetRelation('dates');

    expect(app(EmbargoService::class)->availableDate($resource))->toBeNull();
});

test('no DataCite POST is made before Available', function (): void {
    $resource = embargoResource('2027-01-01');
    $this->travelTo(Carbon::parse('2026-12-31 23:59:59', 'Europe/Berlin'));
    Http::fake();

    expect(fn () => app(DataCiteRegistrationService::class)->registerDoi($resource, '10.83279'))
        ->toThrow(InvalidArgumentException::class, 'before 2027-01-01');
    Http::assertNothingSent();
});

test('an ambiguous DataCite create response does not automatically send a second POST', function (): void {
    $resource = embargoResource('2027-01-01');
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));
    config(['datacite.transport_transient_attempts' => 3]);
    Http::fakeSequence()
        ->push(['errors' => [['title' => 'Temporary failure']]], 500)
        ->push(['data' => ['id' => '10.83279/duplicate']], 201);

    expect(fn () => app(DataCiteRegistrationService::class)->registerDoi($resource, '10.83279'))
        ->toThrow(RequestException::class);
    Http::assertSentCount(1);
    expect($resource->fresh()->doi)->toBeNull()
        ->and($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->landingPage->fresh()->is_published)->toBeFalse();
});

test('a definitive DataCite rejection clears the pending attempt', function (): void {
    $resource = embargoResource('2027-01-01');
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));
    Http::fake(['*datacite.org/*' => Http::response(['errors' => [['title' => 'Invalid metadata']]], 422)]);

    expect(fn () => app(DataCiteRegistrationService::class)->registerDoi($resource, '10.83279'))
        ->toThrow(RequestException::class);
    expect($resource->fresh()->embargo_registration_started_at)->toBeNull()
        ->and($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);
});

test('pending embargo registration blocks editor writes until reconciliation', function (): void {
    $resource = embargoResource('2027-01-01');
    expect(app(EmbargoService::class)->claimRegistration($resource, '10.83279'))->toBeTrue();

    expect(fn () => app(ResourceStorageService::class)->store(['resourceId' => $resource->id]))
        ->toThrow(ValidationException::class);
    expect($resource->fresh()->embargo_registration_started_at)->not->toBeNull();
});

test('due registration exports DataCite Available and Open access and uses a stable URL', function (): void {
    $resource = embargoResource('2027-01-01');
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));
    Http::fake(['*datacite.org/*' => Http::response(['data' => ['id' => '10.83279/embargo']], 201)]);

    $response = app(DataCiteRegistrationService::class)->registerDoi($resource, '10.83279');
    expect($response['data']['id'])->toBe('10.83279/embargo');
    Http::assertSent(function ($request) use ($resource): bool {
        $attributes = $request->data()['data']['attributes'];
        $available = collect($attributes['dates'])->firstWhere('dateType', 'Available');
        $openRight = collect($attributes['rightsList'])->firstWhere('rightsIdentifier', AccessLevel::OPEN->coarIdentifier());

        return $request->method() === 'POST'
            && $attributes['url'] === url("/datasets/{$resource->id}")
            && $available['date'] === '2027-01-01'
            && $openRight['rights'] === AccessLevel::OPEN->label();
    });
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);

    $resource->doi = $response['data']['id'];
    $resource->save();
    app(EmbargoService::class)->completeRelease($resource);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->landingPage->fresh()->is_published)->toBeTrue()
        ->and($resource->fresh(['landingPage'])->publicStatus())->toBe('published');
    $releasedAttributes = (new DataCiteJsonExporter)->export($resource->fresh())['data']['attributes'];
    expect(collect($releasedAttributes['dates'])->firstWhere('dateType', 'Available')['date'])->toBe('2027-01-01')
        ->and(collect($releasedAttributes['rightsList'])->firstWhere('rightsIdentifier', AccessLevel::OPEN->coarIdentifier()))->not->toBeNull()
        ->and(collect($releasedAttributes['rightsList'])->firstWhere('rightsIdentifier', AccessLevel::EMBARGOED->coarIdentifier()))->toBeNull();
    $releasedXml = (new DataCiteXmlExporter)->export($resource->fresh());
    $document = new DOMDocument;
    expect($document->loadXML($releasedXml))->toBeTrue()
        ->and($document->schemaValidate(resource_path('data/scheme/datacite-4.7/metadata.xsd')))->toBeTrue()
        ->and($releasedXml)->toContain('rightsIdentifier="'.AccessLevel::OPEN->coarIdentifier().'"')
        ->not->toContain('rightsIdentifier="'.AccessLevel::EMBARGOED->coarIdentifier().'"');
});

test('DataCite JSON and XML use Available and the COAR embargo right', function (): void {
    $resource = embargoResource('2027-01-01');
    $resource->doi = '10.83279/embargo-schema';
    $resource->save();
    $attributes = (new DataCiteJsonExporter)->export($resource)['data']['attributes'];
    $xml = (new DataCiteXmlExporter)->export($resource);
    $document = new DOMDocument;
    expect($document->loadXML($xml))->toBeTrue();
    expect($document->schemaValidate(resource_path('data/scheme/datacite-4.7/metadata.xsd')))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('d', 'http://datacite.org/schema/kernel-4');

    expect((new JsonSchemaValidator)->validate($attributes))->toBeTrue()
        ->and(collect($attributes['dates'])->firstWhere('dateType', 'Available')['date'])->toBe('2027-01-01')
        ->and(collect($attributes['rightsList'])->firstWhere('rightsIdentifier', AccessLevel::EMBARGOED->coarIdentifier()))->not->toBeNull()
        ->and($xpath->evaluate('string(/d:resource/d:dates/d:date[@dateType="Available"])'))->toBe('2027-01-01')
        ->and($xpath->query('/d:resource/d:dates/d:date[@dateType="Embargo"]'))->toHaveCount(0);
});
