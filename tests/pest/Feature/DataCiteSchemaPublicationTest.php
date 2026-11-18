<?php

declare(strict_types=1);

use App\Http\Controllers\DataCiteSchemaController;
use App\Services\DataCite\DataCiteDateFormatService;
use App\Services\DataCite\DataCiteSchemaPublicationService;
use App\Services\DataCiteJsonLdContextService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\File;
use Opis\JsonSchema\Validator;

covers(DataCiteSchemaController::class, DataCiteSchemaPublicationService::class);

test('publishes checksummed DataCite artifacts anonymously without cookies', function (?string $revision, string $name, string $type) {
    $this->withMiddleware(PreventRequestForgery::class);
    $url = '/schemas/datacite/4.7/'.($revision === null ? '' : $revision.'/').$name;
    $file = app(DataCiteSchemaPublicationService::class)->publicFile($name, $revision);
    $content = file_get_contents($file['path']);
    $response = $this->get($url)->assertOk()
        ->assertHeader('Content-Type', $type.'; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertContent($content);
    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->get('ETag'))->toBe('"'.hash('sha256', $content).'"')
        ->and($response->headers->get('Cache-Control'))->toContain($revision === null ? 'no-cache' : 'immutable');
    $head = $this->head($url)->assertOk()->assertContent('');
    $cached = $this->get($url, ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304)->assertContent('');
    $modified = $this->get($url, ['If-Modified-Since' => $response->headers->get('Last-Modified')])->assertStatus(304);
    foreach ([$head, $cached, $modified] as $result) {
        expect($result->headers->getCookies())->toBeEmpty();
    }
})->with([null, 'v1'])->with([
    'JSON schema' => ['schema.json', 'application/schema+json'],
    'JSON-LD context' => ['context.jsonld', 'application/ld+json'],
    'manifest' => ['manifest.json', 'application/json'],
    'documentation' => ['README.md', 'text/plain'],
]);

test('supports browser validators across origins for all three formats and the old context URL', function (string $url) {
    $this->withMiddleware(PreventRequestForgery::class);
    $origin = ['Origin' => 'https://validator.example.org'];
    $response = $this->get($url, $origin)->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
    $this->head($url, $origin)->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
    $this->get($url, $origin + ['If-None-Match' => $response->headers->get('ETag')])
        ->assertStatus(304)->assertHeader('Access-Control-Allow-Origin', '*');
    expect($response->headers->get('Access-Control-Expose-Headers'))->toContain('ETag');
    $preflight = $this->options($url, [], $origin + [
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'If-None-Match',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
    expect($preflight->headers->getCookies())->toBeEmpty();
})->with([
    '/schemas/igsn/2.0/core.xsd',
    '/schemas/igsn/2.0/preview-1/core.xsd',
    '/schemas/datacite/4.7/schema.json',
    '/schemas/datacite/4.7/v1/schema.json',
    '/schemas/datacite/4.7/context.jsonld',
    '/schemas/datacite/4.7/v1/context.jsonld',
    '/metadata/contexts/datacite-4.7-v1.jsonld',
]);

test('keeps the original context bytes at the old URL and both new URLs', function () {
    $bytes = file_get_contents(base_path(DataCiteJsonLdContextService::FILE));
    foreach ([DataCiteJsonLdContextService::PATH, DataCiteJsonLdContextService::ALIAS_PATH, DataCiteJsonLdContextService::LEGACY_PATH] as $path) {
        $this->get($path)->assertOk()->assertContent($bytes);
    }
});

test('downloaded JSON schema validates dates without ERNIE format registrations', function () {
    $schema = json_decode($this->get('/schemas/datacite/4.7/v1/schema.json')->assertOk()->getContent());
    $validator = new Validator;
    $base = [
        'creators' => [['name' => 'Example']], 'titles' => [['title' => 'Observations']],
        'publisher' => 'GFZ', 'publicationYear' => '2026', 'types' => ['resourceTypeGeneral' => 'Dataset'],
    ];
    foreach (['0000-02-29', '-0400-02-29', '1900-02-29', '2000-02-29', '2024-02-29', '2025-02-29',
        '2026-04-31', '2026-07-16T12:30+14:00', '2026-07-16T12:30+14:01', '/2026', '2026/', '/', "2026\n"] as $date) {
        $data = json_decode(json_encode($base + ['dates' => [['date' => $date, 'dateType' => 'Created']]]));
        expect($validator->validate($data, $schema)->isValid())->toBe(DataCiteDateFormatService::isDate($date), $date);
    }
});

test('switches the current revision while retaining immutable v1 artifacts', function () {
    $url = '/schemas/datacite/4.7/schema.json';
    $etag = $this->get($url)->assertOk()->headers->get('ETag');
    $directory = sys_get_temp_dir().'/datacite-revision-'.bin2hex(random_bytes(8));
    File::copyDirectory(config('datacite.schemas.revisions.v1'), $directory);
    try {
        $schema = json_decode(file_get_contents($directory.'/schema.json'), true, flags: JSON_THROW_ON_ERROR);
        $schema['$id'] = 'https://dataservices.gfz.de/schemas/datacite/4.7/v2/schema.json';
        $bytes = json_encode($schema, JSON_THROW_ON_ERROR);
        file_put_contents($directory.'/schema.json', $bytes);
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['revision'] = 'v2';
        $manifest['files']['schema.json']['sha256'] = hash('sha256', $bytes);
        $manifest['files']['schema.json']['bytes'] = strlen($bytes);
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        config(['datacite.schemas.revision' => 'v2', 'datacite.schemas.revisions.v2' => $directory]);
        $updated = $this->get($url, ['If-None-Match' => $etag])->assertOk();
        expect($updated->headers->get('ETag'))->not->toBe($etag);
        $this->get('/schemas/datacite/4.7/v1/schema.json', ['If-None-Match' => $etag])->assertStatus(304);
        $this->get('/schemas/datacite/4.7/v2/schema.json')->assertOk()->assertHeader('ETag', $updated->headers->get('ETag'));
        expect(app(DataCiteJsonLdContextService::class)->localUrl())->toEndWith('/v1/context.jsonld');
    } finally {
        File::deleteDirectory($directory);
    }
});

test('rejects files whose bytes no longer match the published manifest', function () {
    $directory = sys_get_temp_dir().'/datacite-integrity-'.bin2hex(random_bytes(8));
    File::copyDirectory(config('datacite.schemas.revisions.v1'), $directory);
    try {
        file_put_contents($directory.'/schema.json', '{}');
        config(['datacite.schemas.revisions.v1' => $directory]);
        expect(fn () => app(DataCiteSchemaPublicationService::class)->publicFile('schema.json'))->toThrow(RuntimeException::class, 'has changed');
    } finally {
        File::deleteDirectory($directory);
    }
});

test('does not publish unknown revisions, standards or files', function (string $path) {
    $this->get('/schemas/'.$path)->assertNotFound()->assertHeaderMissing('Location');
    $this->head('/schemas/'.$path)->assertNotFound()->assertHeaderMissing('Location');
})->with([
    'datacite/4.7/.env', 'datacite/4.7/v1/.env', 'datacite/4.7/../schema.json',
    'datacite/4.7/v1/../../datacite_4.7_schema.json', 'datacite/4.7/v1/context-provenance.json',
    'datacite/4.7/v2/schema.json', 'datacite/4.7/v0/schema.json', 'datacite/4.6/schema.json',
    'datacite/4.7/metadata.xsd', 'iso-19115-3/2023/ernie-profile.xsd', 'dcat-ap/3.0.1/rdf-envelope.xsd',
]);
