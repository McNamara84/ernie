<?php

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteRegistrationService;
use Illuminate\Support\Facades\Http;

test('registerIgsn sends descriptions with datacite breaks', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
    ]);

    $resource = Resource::factory()->create([
        'doi' => '10.83279/IGSN-1004',
    ]);
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

    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => $resource->doi,
                'type' => 'dois',
                'attributes' => ['doi' => $resource->doi],
            ],
        ], 201),
    ]);

    $response = app(DataCiteRegistrationService::class)->registerIgsn($resource);

    expect($response['data']['id'])->toBe($resource->doi);

    Http::assertSent(function ($request) use ($resource) {
        $body = json_decode($request->body(), true);
        $description = $body['data']['attributes']['descriptions'][0]['description'];

        return $request->method() === 'POST'
            && $body['data']['attributes']['doi'] === $resource->doi
            && $description === 'First line.<br><br>Second line.'
            && ! str_contains($description, '<strong>');
    });
});
