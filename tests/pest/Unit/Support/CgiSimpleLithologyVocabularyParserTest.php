<?php

declare(strict_types=1);

use App\Support\CgiSimpleLithologyVocabularyParser;

covers(CgiSimpleLithologyVocabularyParser::class);

function simpleLithologyBinding(string $id, string $label, ?string $broader = null, ?string $definition = null): array
{
    $binding = [
        'concept' => ['type' => 'uri', 'value' => 'http://resource.geosciml.org/classifier/cgi/lithology/'.$id],
        'prefLabel' => ['type' => 'literal', 'xml:lang' => 'en', 'value' => $label],
    ];
    if ($broader !== null) {
        $binding['broader'] = ['type' => 'uri', 'value' => 'http://resource.geosciml.org/classifier/cgi/lithology/'.$broader];
    }
    if ($definition !== null) {
        $binding['definition'] = ['type' => 'literal', 'xml:lang' => 'en', 'value' => $definition];
    }

    return $binding;
}

it('builds every path of a deterministic polyhierarchical vocabulary', function (): void {
    $bindings = [
        simpleLithologyBinding('material', 'Material'),
        simpleLithologyBinding('rock', 'Rock', 'material', 'Consolidated aggregate.'),
        simpleLithologyBinding('igneous', 'Igneous material', 'material'),
        simpleLithologyBinding('igneous', 'Igneous material', 'rock'),
    ];

    $payload = app(CgiSimpleLithologyVocabularyParser::class)->buildPayload($bindings, '2025-06-04', 1, 10, 10);

    expect($payload['total'])->toBe(3)
        ->and($payload['pathCount'])->toBe(4)
        ->and($payload['data'][0]['text'])->toBe('Material')
        ->and($payload['data'][0]['children'])->toHaveCount(2)
        ->and($payload['data'][0]['children'][1]['children'][0]['id'])
        ->toBe('http://resource.geosciml.org/classifier/cgi/lithology/igneous')
        ->and($payload['source']['dateModified'])->toBe('2025-06-04')
        ->and($payload['source']['sha256'])->toMatch('/^[a-f0-9]{64}$/');
});

it('produces the same source hash when SPARQL binding order changes', function (): void {
    $bindings = [
        simpleLithologyBinding('material', 'Material'),
        simpleLithologyBinding('rock', 'Rock', 'material'),
    ];
    $parser = app(CgiSimpleLithologyVocabularyParser::class);

    $first = $parser->buildPayload($bindings, null, 1, 10, 10);
    $second = $parser->buildPayload(array_reverse($bindings), null, 1, 10, 10);

    expect($first['source']['sha256'])->toBe($second['source']['sha256']);
});

it('accepts regional English labels returned by LANGMATCHES', function (): void {
    $binding = simpleLithologyBinding('material', 'Material');
    $binding['prefLabel']['xml:lang'] = 'en-AU';

    $payload = app(CgiSimpleLithologyVocabularyParser::class)->buildPayload([$binding], null, 1, 10, 10);

    expect($payload['data'][0]['text'])->toBe('Material');
});

it('rejects tampered or incomplete local payloads', function (Closure $tamper, string $message): void {
    $parser = app(CgiSimpleLithologyVocabularyParser::class);
    $payload = $parser->buildPayload([
        simpleLithologyBinding('material', 'Material'),
        simpleLithologyBinding('basalt', 'Basalt', 'material'),
    ], null, 1, 10, 10);
    $tamper($payload);

    expect(fn () => $parser->validatePayload($payload, 1, 10, 10))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'changed label' => [
        function (array &$payload): void {
            $payload['data'][0]['children'][0]['text'] = 'Tampered basalt';
        },
        'content hash is invalid',
    ],
    'inconsistent count' => [
        function (array &$payload): void {
            $payload['pathCount'] = 999;
        },
        'inconsistent concept or path counts',
    ],
    'foreign concept URI' => [
        function (array &$payload): void {
            $payload['data'][0]['id'] = 'https://example.test/foreign';
        },
        'invalid concept node',
    ],
]);

it('rejects cycles, foreign concept URIs, and implausible counts', function (array $bindings, string $message): void {
    expect(fn () => app(CgiSimpleLithologyVocabularyParser::class)->buildPayload($bindings, null, 1, 10, 10))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'cycle' => [[
        simpleLithologyBinding('root', 'Root'),
        simpleLithologyBinding('a', 'A', 'root'),
        simpleLithologyBinding('a', 'A', 'b'),
        simpleLithologyBinding('b', 'B', 'a'),
    ], 'contains a cycle'],
    'foreign URI' => [[
        [
            'concept' => ['type' => 'uri', 'value' => 'https://example.org/foreign'],
            'prefLabel' => ['type' => 'literal', 'xml:lang' => 'en', 'value' => 'Foreign'],
        ],
    ], 'Unexpected Simple Lithology concept URI'],
]);

it('rejects a concept count outside the configured range', function (): void {
    expect(fn () => app(CgiSimpleLithologyVocabularyParser::class)->buildPayload(
        [simpleLithologyBinding('material', 'Material')],
        null,
        2,
        10,
        10,
    ))->toThrow(RuntimeException::class, 'outside the allowed range');
});
