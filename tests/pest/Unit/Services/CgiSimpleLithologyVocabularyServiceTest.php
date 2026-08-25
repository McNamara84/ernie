<?php

declare(strict_types=1);

use App\Services\CgiSimpleLithologyVocabularyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(CgiSimpleLithologyVocabularyService::class);

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
    config([
        'simple_lithology.min_concepts' => 1,
        'simple_lithology.max_concepts' => 10,
        'simple_lithology.max_depth' => 10,
    ]);
});

function fakeSimpleLithologyApi(string|Closure $leafLabel = 'Basalt'): void
{
    Http::fake(function (Request $request) use ($leafLabel) {
        $query = (string) $request['query'];
        if (str_contains($query, 'dateModified')) {
            return Http::response([
                'results' => ['bindings' => [[
                    'dateModified' => ['type' => 'literal', 'value' => '2025-06-04'],
                ]]],
            ], 200, ['Content-Type' => 'application/sparql-results+json']);
        }

        return Http::response([
            'results' => ['bindings' => [
                simpleLithologyServiceBinding('material', 'Material'),
                simpleLithologyServiceBinding(
                    'basalt',
                    $leafLabel instanceof Closure ? $leafLabel() : $leafLabel,
                    'material',
                ),
            ]],
        ], 200, ['Content-Type' => 'application/sparql-results+json']);
    });
}

function simpleLithologyServiceBinding(string $id, string $label, ?string $broader = null): array
{
    $binding = [
        'concept' => ['type' => 'uri', 'value' => 'http://resource.geosciml.org/classifier/cgi/lithology/'.$id],
        'prefLabel' => ['type' => 'literal', 'xml:lang' => 'en', 'value' => $label],
    ];
    if ($broader !== null) {
        $binding['broader'] = ['type' => 'uri', 'value' => 'http://resource.geosciml.org/classifier/cgi/lithology/'.$broader];
    }

    return $binding;
}

it('downloads, validates, and atomically stores a vocabulary', function (): void {
    fakeSimpleLithologyApi();

    $payload = app(CgiSimpleLithologyVocabularyService::class)->updateLocalVocabulary();

    Storage::disk('local')->assertExists('cgi-simple-lithology.json');
    expect($payload['total'])->toBe(2)
        ->and(app(CgiSimpleLithologyVocabularyService::class)->localPayload()['source']['sha256'])
        ->toBe($payload['source']['sha256']);
});

it('detects content changes even when the concept count stays unchanged', function (): void {
    $leafLabel = 'Basalt';
    fakeSimpleLithologyApi(static function () use (&$leafLabel): string {
        return $leafLabel;
    });
    $service = app(CgiSimpleLithologyVocabularyService::class);
    $service->updateLocalVocabulary();

    $leafLabel = 'Basaltic rock';
    $comparison = $service->compareWithRemote();

    expect($comparison['localCount'])->toBe(2)
        ->and($comparison['remoteCount'])->toBe(2)
        ->and($comparison['updateAvailable'])->toBeTrue()
        ->and($comparison['localSha'])->not->toBe($comparison['remoteSha']);
});

it('keeps the last known good file when an update is invalid', function (): void {
    Storage::disk('local')->put('cgi-simple-lithology.json', '{"known":"good"}');
    Http::fake(fn () => Http::response(
        ['results' => ['bindings' => []]],
        200,
        ['Content-Type' => 'application/sparql-results+json'],
    ));

    expect(fn () => app(CgiSimpleLithologyVocabularyService::class)->updateLocalVocabulary())
        ->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->get('cgi-simple-lithology.json'))->toBe('{"known":"good"}');
});

it('rejects unexpected response content types and unsafe configured endpoints', function (): void {
    Http::fake(fn () => Http::response('{}', 200, ['Content-Type' => 'text/html']));
    expect(fn () => app(CgiSimpleLithologyVocabularyService::class)->fetchRemotePayload())
        ->toThrow(RuntimeException::class, 'unexpected content type');

    config(['simple_lithology.endpoint' => 'http://cgi-api.vocabs.ga.gov.au/sparql']);
    expect(fn () => app(CgiSimpleLithologyVocabularyService::class)->fetchRemotePayload())
        ->toThrow(RuntimeException::class, 'not allowed');
});
