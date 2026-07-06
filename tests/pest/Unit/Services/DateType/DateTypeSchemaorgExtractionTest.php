<?php

declare(strict_types=1);

use App\Services\DateType\DateTypeSchemaorgExtraction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(DateTypeSchemaorgExtraction::class);

it ('extracts created and issued additions from allowed GFZ Potsdam hosts', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => 'https://dataservices.gfz-potsdam.de/example-dataset', 
            'dateCreated' => '2016-07-03',
            'datePublished' => '2020-07-03',
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(2)
        ->and($result[0])->toMatchArray
        ([
            'suggestion_kind' => 'addition',
            'target_date_type' => 'Created',
            'normalized_value' => '2016-07-03',
            'source_url' => 'https://dataservices.gfz-potsdam.de/example-dataset',
            'evidence_source' => 'schema.org',
            'evidence_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'schema_org_field' => 'dateCreated',
            'confidence' => 'high',
            'is_ambiguous' => false,
        ])
        ->and($result[1])->toMatchArray
        ([
            'suggestion_kind' => 'addition',
            'target_date_type' => 'Issued',
            'normalized_value' => '2020-07-03',
            'source_url' => 'https://dataservices.gfz-potsdam.de/example-dataset',
            'evidence_source' => 'schema.org',
            'evidence_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'schema_org_field' => 'datePublished',
            'confidence' => 'high',
            'is_ambiguous' => false,           
        ]);

    Http::assertSentCount(1);
});

it ('extracts created addition from allowed GFZ Potsdam hosts', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => 'https://dataservices.gfz-potsdam.de/example-dataset', 
            'dateCreated' => '2016-07-03',
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray
        ([
            'suggestion_kind' => 'addition',
            'target_date_type' => 'Created',
            'normalized_value' => '2016-07-03',
            'source_url' => 'https://dataservices.gfz-potsdam.de/example-dataset',
            'evidence_source' => 'schema.org',
            'evidence_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'schema_org_field' => 'dateCreated',
            'confidence' => 'high',
            'is_ambiguous' => false,
        ]);

    Http::assertSentCount(1);
});

it ('accepts integer dateCreated values from allowed GFZ Potsdam hosts', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => 'https://dataservices.gfz-potsdam.de/example-dataset', 
            'dateCreated' => 2016,
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray
        ([
            'suggestion_kind' => 'addition',
            'target_date_type' => 'Created',
            'normalized_value' => '2016',
            'source_url' => 'https://dataservices.gfz-potsdam.de/example-dataset',
            'evidence_source' => 'schema.org',
            'evidence_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'schema_org_field' => 'dateCreated',
            'confidence' => 'high',
            'is_ambiguous' => false,
        ]);

    Http::assertSentCount(1);
});

it ('extracts created and issued additions from allowed GFZ hosts', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => 'https://dataservices.gfz.de/example-dataset', 
            'dateCreated' => '2016-07-03',
            'datePublished' => '2020-07-03',
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(2)
        ->and($result[0])->toMatchArray
        ([
            'suggestion_kind' => 'addition',
            'target_date_type' => 'Created',
            'normalized_value' => '2016-07-03',
            'source_url' => 'https://dataservices.gfz.de/example-dataset',
            'evidence_source' => 'schema.org',
            'evidence_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'schema_org_field' => 'dateCreated',
            'confidence' => 'high',
            'is_ambiguous' => false,
        ])
        ->and($result[1])->toMatchArray
        ([
            'suggestion_kind' => 'addition',
            'target_date_type' => 'Issued',
            'normalized_value' => '2020-07-03',
            'source_url' => 'https://dataservices.gfz.de/example-dataset',
            'evidence_source' => 'schema.org',
            'evidence_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'schema_org_field' => 'datePublished',
            'confidence' => 'high',
            'is_ambiguous' => false,           
        ]);

    Http::assertSentCount(1);
});

it ('extracts issued addition from allowed GFZ hosts', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => 'https://dataservices.gfz.de/example-dataset', 
            'datePublished' => '2020-07-03',
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray
        ([
            'suggestion_kind' => 'addition',
            'target_date_type' => 'Issued',
            'normalized_value' => '2020-07-03',
            'source_url' => 'https://dataservices.gfz.de/example-dataset',
            'evidence_source' => 'schema.org',
            'evidence_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'schema_org_field' => 'datePublished',
            'confidence' => 'high',
            'is_ambiguous' => false,           
        ]);

    Http::assertSentCount(1);
});

it ('skips created and issued additions from not allowed hosts', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => 'https://geofon.gfz.de/software/eqexplorer/', 
            'dateCreated' => '2016-07-03',
            'datePublished' => '2020-07-03',
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray
        ([
            'source_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'probe_method' => 'SKIP',
            'skip_reason' => 'unsupported_source_url',
            'error' => null,
            'raw_evidence' => [], 
            'suggestions' => [],
        ]);
    
    Http::assertSentCount(1);

});

it ('ignores invalid or unsupported date values in schema.org', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => 'https://dataservices.gfz.de/example-dataset', 
            'dateCreated' => 'abc',
            'datePublished' => '2020-02-31',
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toBe([]);
    
    Http::assertSentCount(1);
});

it ('skips created and issued additions when url field in schema.org is not a string', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([
            'url' => [], 
            'dateCreated' => '2016-07-03',
            'datePublished' => '2020-07-03',
        ], 200)
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray
        ([
            'source_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001',
            'probe_method' => 'SKIP',
            'skip_reason' => 'unsupported_source_url',
            'error' => null,
            'raw_evidence' => [], 
            'suggestions' => [],
        ]);
    
    Http::assertSentCount(1);
});

it ('does not follow schema.org redirects', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response('', 302,[
            'Location' => 'https://redirect.org',
        ]),
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result[0]['probe_method'])->toBe('SKIP')
        ->and($result[0]['skip_reason'])->toBe('schemaorg_unreachable');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://redirect.org');
});

it ('skips schema.org request when loading takes longer than timeout', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => function(){
            throw new ConnectionException('Connection timed out after 5 seconds');
        },
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(1)
        ->and($result[0]['probe_method'])->toBe('SKIP')
        ->and($result[0]['skip_reason'])->toBe('schemaorg_direct_failed')
        ->and($result[0]['error'])->toBe('Connection timed out after 5 seconds');

     Http::assertNothingSent();
});

it ('skips missing responses as unreachable', function () {
    Http::fake
    ([
        'https://data.crosscite.org/application/vnd.schemaorg.ld+json/105880.test.2026.001' => Http::response([], 404),
    ]);

    $service = app(DateTypeSchemaorgExtraction::class);
    $result = $service->loadAllowedSchemaorg('105880.test.2026.001');

    expect($result)->toHaveCount(1)
        ->and($result[0]['probe_method'])->toBe('SKIP')
        ->and($result[0]['skip_reason'])->toBe('schemaorg_unreachable');

    Http::assertSentCount(1);
});