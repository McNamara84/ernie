<?php

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteRegistrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../../Support/DataCitePublicationFixtures.php';

test('registerIgsn sends descriptions with datacite breaks', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.client_id' => 'test.repo',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
    ]);

    $resource = Resource::factory()->create([
        'doi' => '10.83279/IGSN-1004',
    ]);
    completeDataCiteFixture($resource);
    $resource->descriptions()->delete();
    URL::forceRootUrl('https://ernie.example');
    URL::forceScheme('https');
    LandingPage::factory()->create([
        'resource_id' => $resource->id,
    ]);
    $type = DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract', 'is_active' => true]
    );
    Description::create([
        'resource_id' => $resource->id,
        'value' => "First line.\n\nSecond line.",
        'landing_page_html' => '<p>First <strong>line</strong>.</p><p>Second line.</p>',
        'description_type_id' => $type->id,
    ]);

    Http::fake(fn ($request) => match ($request->method()) {
        'GET' => Http::response([], 404),
        'HEAD' => Http::response('', 200),
        default => Http::response(dataCiteRecord($resource->doi, attributes: $request['data']['attributes']), 201),
    });

    $response = app(DataCiteRegistrationService::class)->registerIgsn($resource);

    expect($response['data']['id'])->toBe($resource->doi);

    Http::assertSent(function ($request) use ($resource) {
        if ($request->method() !== 'POST') {
            return false;
        }
        $body = json_decode($request->body(), true);
        $description = $body['data']['attributes']['descriptions'][0]['description'];

        return $request->method() === 'POST'
            && $body['data']['attributes']['doi'] === $resource->doi
            && $description === 'First line.<br><br>Second line.'
            && ! str_contains($description, '<strong>');
    });
});
