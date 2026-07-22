<?php

declare(strict_types=1);

use App\Services\MslLaboratorySourceResolverService;
use App\Services\MslLaboratoryVocabularyService;
use App\Services\VocabularyCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(MslLaboratoryVocabularyService::class);

function mslVocabularyServiceTestLab(array $overrides = []): array
{
    return array_replace([
        'identifier' => 'lab-001',
        'name' => 'Rock Physics Lab',
        'display_name' => 'Rock Physics Lab — GFZ',
        'affiliation_name' => 'GFZ Helmholtz Centre',
        'affiliation_ror' => 'https://ror.org/04z8jg394',
        'scientific_domain' => 'Geosciences',
        'country' => 'Germany',
    ], $overrides);
}

function makeMslVocabularyService(array $source = []): MslLaboratoryVocabularyService
{
    $resolver = Mockery::mock(MslLaboratorySourceResolverService::class);
    $resolver->shouldReceive('resolveLatest')->andReturn(array_replace([
        'repository' => 'UtrechtUniversity/msl_vocabularies',
        'ref' => 'main',
        'version' => '1.1',
        'path' => 'vocabularies/labs/1.1/laboratories.json',
        'sha' => '0000000000000000000000000000000000000000',
        'download_url' => 'https://raw.example.test/laboratories.json',
    ], $source));

    return new MslLaboratoryVocabularyService(
        $resolver,
        app(VocabularyCacheService::class)
    );
}

function makeMslVocabularyServiceWithResponse(
    mixed $body,
    int $status = 200,
    array $source = []
): MslLaboratoryVocabularyService {
    $content = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

    Http::fake([
        'raw.example.test/*' => Http::response(
            $content,
            $status,
            ['Content-Type' => 'application/json']
        ),
    ]);

    return makeMslVocabularyService(array_replace([
        'sha' => sha1('blob '.strlen($content)."\0".$content),
    ], $source));
}

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
    config()->set([
        'msl.http_retries' => 1,
        'msl.http_retry_delay_ms' => 0,
    ]);
});

it('downloads, normalizes and atomically stores a complete vocabulary', function (): void {
    $service = makeMslVocabularyServiceWithResponse([
        mslVocabularyServiceTestLab([
            'name' => '  Rock Physics Lab  ',
            'additional_future_field' => 'ignored',
        ]),
        mslVocabularyServiceTestLab([
            'identifier' => 'lab-002',
            'display_name' => 'Rock Physics Lab — Utrecht',
            'affiliation_name' => 'Utrecht University',
            'affiliation_ror' => '',
            'country' => 'Netherlands',
        ]),
    ]);
    Cache::put('msl_laboratories', ['stale']);

    $payload = $service->updateLocal();

    expect($payload['version'])->toBe('1.1')
        ->and($payload['total'])->toBe(2)
        ->and($payload['data'][0]['name'])->toBe('Rock Physics Lab')
        ->and($payload['data'][0])->not->toHaveKey('additional_future_field')
        ->and($payload['data'][1]['affiliation_ror'])->toBeNull()
        ->and($payload['source']['sha'])->toBeString()->toHaveLength(40)
        ->and(Cache::get('msl_laboratories'))->toBeNull();

    Storage::assertExists('msl-laboratories.json');
    expect(Storage::allFiles())->toBe(['msl-laboratories.json']);
});

it('normalizes syntactically invalid ROR values to null', function (): void {
    $service = makeMslVocabularyServiceWithResponse([
        mslVocabularyServiceTestLab(['affiliation_ror' => 'http://example.test/not-ror']),
    ]);

    $payload = $service->fetchLatest();

    expect($payload['data'][0]['affiliation_ror'])->toBeNull();
});

it('allows duplicate names while requiring unique identifiers', function (): void {
    $service = makeMslVocabularyServiceWithResponse([
        mslVocabularyServiceTestLab(),
        mslVocabularyServiceTestLab(['identifier' => 'lab-002']),
    ]);

    expect($service->fetchLatest()['total'])->toBe(2);
});

it('rejects duplicate identifiers', function (): void {
    $service = makeMslVocabularyServiceWithResponse([
        mslVocabularyServiceTestLab(),
        mslVocabularyServiceTestLab(),
    ]);

    expect(fn () => $service->fetchLatest())
        ->toThrow(RuntimeException::class, "identifier 'lab-001' occurs more than once");
});

it('rejects missing and empty required fields', function (array $laboratory, string $message): void {
    $service = makeMslVocabularyServiceWithResponse([$laboratory]);

    expect(fn () => $service->fetchLatest())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'missing display name' => [
        array_diff_key(mslVocabularyServiceTestLab(), ['display_name' => true]),
        "missing required field 'display_name'",
    ],
    'empty country' => [
        mslVocabularyServiceTestLab(['country' => '   ']),
        "invalid 'country' value",
    ],
]);

it('rejects malformed, non-array and empty JSON roots', function (mixed $body, string $message): void {
    $service = makeMslVocabularyServiceWithResponse($body);

    expect(fn () => $service->fetchLatest())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'malformed JSON' => ['{broken', 'not valid JSON'],
    'object root' => [['data' => []], 'must contain a JSON array'],
    'empty root' => [[], 'at least one laboratory'],
]);

it('preserves the previous local file when download validation fails', function (): void {
    $previous = json_encode([
        'version' => '1.0',
        'lastUpdated' => '2026-01-01T00:00:00+00:00',
        'total' => 1,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => 'vocabularies/labs/1.0/laboratories.json',
            'sha' => 'old-sha',
        ],
        'data' => [mslVocabularyServiceTestLab()],
    ], JSON_THROW_ON_ERROR);
    Storage::put('msl-laboratories.json', $previous);
    $service = makeMslVocabularyServiceWithResponse([['identifier' => 'incomplete']]);

    expect(fn () => $service->updateLocal())
        ->toThrow(RuntimeException::class);
    expect(Storage::get('msl-laboratories.json'))->toBe($previous);
});

it('returns a strict public payload without source metadata', function (): void {
    $service = makeMslVocabularyServiceWithResponse([mslVocabularyServiceTestLab()]);
    $service->updateLocal();

    $public = $service->getPublicPayload();

    expect(array_keys($public))->toBe(['version', 'lastUpdated', 'total', 'data'])
        ->and($public)->not->toHaveKey('source');
});

it('returns null for a missing local vocabulary and rejects corrupt local data', function (): void {
    $service = makeMslVocabularyServiceWithResponse([mslVocabularyServiceTestLab()]);

    expect($service->getLocalPayload())->toBeNull();

    Storage::put('msl-laboratories.json', '{broken');

    expect(fn () => $service->getLocalPayload())
        ->toThrow(RuntimeException::class, 'contains invalid JSON');
});

it('rejects semantically invalid local wrapper metadata', function (string $field, mixed $value): void {
    $service = makeMslVocabularyServiceWithResponse([mslVocabularyServiceTestLab()]);
    $payload = [
        'version' => '1.1',
        'lastUpdated' => '2026-07-21T12:00:00+00:00',
        'total' => 1,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => 'vocabularies/labs/1.1/laboratories.json',
            'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'data' => [mslVocabularyServiceTestLab()],
    ];

    if ($field === 'source.sha') {
        $payload['source']['sha'] = $value;
    } else {
        $payload[$field] = $value;
    }

    Storage::put('msl-laboratories.json', json_encode($payload, JSON_THROW_ON_ERROR));

    expect(fn () => $service->getLocalPayload())->toThrow(RuntimeException::class);
})->with([
    'unstable version' => ['version', 'latest'],
    'invalid calendar timestamp' => ['lastUpdated', '2026-02-31T12:00:00+00:00'],
    'non-RFC3339 timestamp' => ['lastUpdated', 'yesterday'],
    'short source SHA' => ['source.sha', 'abc123'],
    'mismatched total' => ['total', 2],
]);

it('accepts the RFC3339 UTC Z designator in a local wrapper', function (): void {
    $service = makeMslVocabularyServiceWithResponse([mslVocabularyServiceTestLab()]);
    Storage::put('msl-laboratories.json', json_encode([
        'version' => '1.1',
        'lastUpdated' => '2026-07-21T12:00:00Z',
        'total' => 1,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => 'vocabularies/labs/1.1/laboratories.json',
            'sha' => 'ABCDEFABCDEFABCDEFABCDEFABCDEFABCDEFABCD',
        ],
        'data' => [mslVocabularyServiceTestLab()],
    ], JSON_THROW_ON_ERROR));

    expect($service->getLocalPayload())
        ->toMatchArray(['version' => '1.1', 'lastUpdated' => '2026-07-21T12:00:00Z'])
        ->and($service->getLocalPayload()['source']['sha'])
        ->toBe('abcdefabcdefabcdefabcdefabcdefabcdefabcd');
});

it('reports remote download failures', function (): void {
    $service = makeMslVocabularyServiceWithResponse([], 502);

    expect(fn () => $service->fetchLatest())
        ->toThrow(RuntimeException::class, 'HTTP 502');
});

it('wraps remote download connection failures with operation context', function (): void {
    Http::fake(['*' => Http::failedConnection('download timed out')]);
    $service = makeMslVocabularyService();

    expect(fn () => $service->fetchLatest())
        ->toThrow(RuntimeException::class, 'Failed to download MSL laboratories vocabulary: download timed out');
});

it('rejects a download that does not match the resolved Git blob SHA', function (): void {
    $service = makeMslVocabularyServiceWithResponse(
        [mslVocabularyServiceTestLab()],
        source: ['sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']
    );

    expect(fn () => $service->fetchLatest())
        ->toThrow(RuntimeException::class, 'does not match its Git blob SHA');
});

it('replaces an existing local file during a second successful update', function (): void {
    $firstBody = json_encode([mslVocabularyServiceTestLab()], JSON_THROW_ON_ERROR);
    $secondBody = json_encode([
        mslVocabularyServiceTestLab(['identifier' => 'lab-002']),
    ], JSON_THROW_ON_ERROR);
    Http::fakeSequence()
        ->push($firstBody, 200, ['Content-Type' => 'application/json'])
        ->push($secondBody, 200, ['Content-Type' => 'application/json']);
    $baseSource = [
        'repository' => 'UtrechtUniversity/msl_vocabularies',
        'ref' => 'main',
        'path' => 'vocabularies/labs/1.1/laboratories.json',
        'download_url' => 'https://raw.example.test/laboratories.json',
    ];
    $resolver = Mockery::mock(MslLaboratorySourceResolverService::class);
    $resolver->shouldReceive('resolveLatest')->twice()->andReturn(
        array_replace($baseSource, [
            'version' => '1.1',
            'sha' => sha1('blob '.strlen($firstBody)."\0".$firstBody),
        ]),
        array_replace($baseSource, [
            'version' => '1.2',
            'path' => 'vocabularies/labs/1.2/laboratories.json',
            'sha' => sha1('blob '.strlen($secondBody)."\0".$secondBody),
        ])
    );
    $service = new MslLaboratoryVocabularyService(
        $resolver,
        app(VocabularyCacheService::class)
    );

    $service->updateLocal();
    $firstContent = Storage::get('msl-laboratories.json');
    $service->updateLocal();
    $secondContent = Storage::get('msl-laboratories.json');

    expect($secondContent)->not->toBe($firstContent)
        ->and(json_decode($secondContent, true, 512, JSON_THROW_ON_ERROR)['version'])->toBe('1.2')
        ->and(json_decode($secondContent, true, 512, JSON_THROW_ON_ERROR)['data'][0]['identifier'])
        ->toBe('lab-002');
});
