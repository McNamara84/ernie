<?php

declare(strict_types=1);

use App\Services\Iso19115\Iso19115JsonValidator;

covers(Iso19115JsonValidator::class);

function minimalIsoJsonDocument(): array
{
    return [
        'conformsTo' => [config('iso19115.json.schema')],
        'type' => 'Feature',
        'geometry' => null,
        'properties' => [
            'contact' => [['role' => 'pointOfContact', 'party' => [['type' => 'CI_Organisation', 'name' => 'Repository']]]],
            'dateInfo' => ['creation' => '2026-10-26'],
            'identificationInfo' => ['citation' => ['title' => 'A dataset'], 'abstract' => 'Research data'],
        ],
    ];
}

test('validates an entire ISO JSON feature and collection offline', function () {
    $document = minimalIsoJsonDocument();
    $validator = app(Iso19115JsonValidator::class);
    expect($validator->validate($document)->errors)->toBe([])
        ->and($validator->validate([
            'conformsTo' => $document['conformsTo'], 'type' => 'FeatureCollection', 'features' => [$document],
        ])->errors)->toBe([]);
});

test('rejects malformed ISO JSON contracts', function (string $path, mixed $value) {
    $document = minimalIsoJsonDocument();
    data_set($document, $path, $value);
    expect(app(Iso19115JsonValidator::class)->validate($document)->isValid())->toBeFalse();
})->with([
    'wrong envelope' => ['type', 'Metadata'],
    'missing contact' => ['properties.contact', []],
    'wrong identifier shape' => ['properties.metadataIdentifier', ['value' => '10.5880/test']],
    'invalid date' => ['properties.dateInfo.creation', '2026-02-30'],
    'invalid timestamp' => ['properties.dateInfo.creation', '2026-10-26T99:00:00Z'],
    'invalid conformance URI' => ['conformsTo', ['not a uri']],
    'identification array' => ['properties.identificationInfo', []],
    'null abstract' => ['properties.identificationInfo.abstract', null],
    'missing party discriminator' => ['properties.contact.0.party', [['name' => 'Repository']]],
    'duplicate party' => ['properties.contact.0.party', [['type' => 'CI_Individual', 'name' => 'A'], ['type' => 'CI_Individual', 'name' => 'A']]],
    'invalid geometry' => ['geometry', ['type' => 'Point', 'coordinates' => ['bad', 1]]],
]);

test('rejects missing document requirements', function (string $key) {
    $document = minimalIsoJsonDocument();
    unset($document[$key]);
    expect(app(Iso19115JsonValidator::class)->validate($document)->isValid())->toBeFalse();
})->with(['conformsTo', 'type', 'geometry', 'properties']);

test('accepts the ISO date precisions and empty required legacy strings', function (string $date) {
    $document = minimalIsoJsonDocument();
    $document['properties']['dateInfo']['creation'] = $date;
    $document['properties']['identificationInfo'] = ['citation' => ['title' => ''], 'abstract' => ''];
    expect(app(Iso19115JsonValidator::class)->validate($document)->errors)->toBe([]);
})->with(['2026', '2026-10', '2024-02-29', '2026-10-26T12:30:00Z']);

test('fails closed for missing schemas and unregistered external schema IDs', function (string $key, string $value) {
    config([$key => $value]);
    expect(app(Iso19115JsonValidator::class)->validate(minimalIsoJsonDocument())->isValid())->toBeFalse();
})->with([
    ['iso19115.json.directory', '/not-a-schema-directory'],
    ['iso19115.json.schema', 'https://example.invalid/unregistered.json'],
]);

test('pins the original ISO schemas and example with verified checksums', function () {
    $directory = config('iso19115.json.directory');
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['commit'])->toBe('1c70b6611d2af322d29d1343c12b1a670dca6acf');
    foreach ($manifest['files'] as $file) {
        expect(hash_file('sha256', $directory.'/'.$file['path']))->toBe($file['sha256']);
    }
});

test('validates the unchanged official example', function () {
    $example = json_decode(file_get_contents(config('iso19115.json.directory').'/json/19115/-4/mdj/1.0.0/examples/C1-19115-4-JSON-example.json'), true, flags: JSON_THROW_ON_ERROR);
    $disabled = [];
    try {
        foreach (['http', 'https'] as $protocol) {
            if (in_array($protocol, stream_get_wrappers(), true) && stream_wrapper_unregister($protocol)) {
                $disabled[] = $protocol;
            }
        }
        expect(app(Iso19115JsonValidator::class)->validate($example)->errors)->toBe([]);
    } finally {
        foreach ($disabled as $protocol) {
            stream_wrapper_restore($protocol);
        }
    }
});
