<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Services\SubjectHierarchy\SubjectHierarchyCacheService;
use App\Services\SubjectHierarchy\SubjectHierarchyVocabularyService;
use App\Support\AnalyticalMethodsVocabularyParser;
use App\Support\ChronostratVocabularyParser;
use App\Support\EuroSciVocParser;
use App\Support\GcmdVocabularyParser;
use App\Support\GemetConceptHierarchyParser;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectHierarchyGraph;
use Illuminate\Support\Facades\Storage;

covers(SubjectHierarchyCacheService::class, SubjectHierarchyGraph::class, SubjectHierarchyVocabularyService::class, GemetConceptHierarchyParser::class);

function rawHierarchyNode(string $id, array $parents = [], string $label = ''): array
{
    return ['id' => 'https://example.org/'.$id, 'text' => $label ?: ucfirst($id),
        'broaderIds' => array_map(fn (string $parent): string => 'https://example.org/'.$parent, $parents)];
}

function unitHierarchyGraph(array $concepts): SubjectHierarchyGraph
{
    (new SubjectHierarchyCacheService)->publishFlat('gcmd-platforms.json', '{"data":[]}', $concepts, 'Platforms', 'https://example.org/scheme');

    return app(SubjectHierarchyVocabularyService::class)->graph('Platforms');
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('preserves all parent edges and deduplicates shared terminal concepts', function (): void {
    $graph = unitHierarchyGraph([rawHierarchyNode('root'), rawHierarchyNode('left', ['root']), rawHierarchyNode('right', ['root']),
        rawHierarchyNode('leaf', ['left', 'right'])]);
    $subtree = $graph->subtree('https://example.org/root');
    expect($subtree['nodes'])->toHaveCount(4)->and($subtree['leaf_ids'])->toBe(['https://example.org/leaf'])
        ->and($graph->concept('https://example.org/leaf')['parents'])->toBe(['https://example.org/left', 'https://example.org/right'])
        ->and($graph->conceptPath('https://example.org/leaf'))->toBe('Root > Left > Leaf');
});

it('keeps competing readers and publishers excluded during both cache replacements', function (): void {
    unitHierarchyGraph([rawHierarchyNode('root'), rawHierarchyNode('old', ['root'])]);
    $file = 'gcmd-platforms.json';
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $competitor = fopen($disk->path('subject-hierarchies/locks/'.hash('sha256', $file).'.lock'), 'c');
    $otherVocabulary = fopen($disk->path('subject-hierarchies/locks/'.hash('sha256', 'gcmd-instruments.json').'.lock'), 'c');
    $proxy = Mockery::mock($manager)->shouldAllowMockingProtectedMethods();
    $proxy->shouldReceive('move')->twice()->andReturnUsing(function (string $source, string $target) use ($disk, $competitor, $otherVocabulary): bool {
        expect(flock($competitor, LOCK_SH | LOCK_NB))->toBeFalse()
            ->and(flock($competitor, LOCK_EX | LOCK_NB))->toBeFalse()
            ->and(flock($otherVocabulary, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($otherVocabulary, LOCK_UN);

        return $disk->move($source, $target);
    });
    Storage::swap($proxy);
    try {
        (new SubjectHierarchyCacheService)->publishFlat($file, 'new editor snapshot',
            [rawHierarchyNode('root'), rawHierarchyNode('new', ['root'])], 'Platforms', 'https://example.org/scheme');
        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($competitor, LOCK_UN);
    } finally {
        Storage::swap($manager);
        fclose($competitor);
        fclose($otherVocabulary);
    }
    $snapshot = (new SubjectHierarchyCacheService)->readSnapshot($file);
    expect($snapshot['source'])->toBe('new editor snapshot')
        ->and(json_decode($snapshot['hierarchy'], true)['source_hash'])->toBe(hash('sha256', $snapshot['source']))
        ->and(array_filter(Storage::allFiles(), fn (string $path): bool => str_ends_with($path, '.tmp') || str_ends_with($path, '.bak')))->toBe([]);
});

it('holds the shared vocabulary lock across both snapshot reads', function (): void {
    unitHierarchyGraph([rawHierarchyNode('root')]);
    $file = 'gcmd-platforms.json';
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $competitor = fopen($disk->path('subject-hierarchies/locks/'.hash('sha256', $file).'.lock'), 'c');
    $proxy = Mockery::mock($manager)->shouldAllowMockingProtectedMethods();
    $proxy->shouldReceive('get')->twice()->andReturnUsing(function (string $path) use ($disk, $competitor): string {
        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeFalse()
            ->and(flock($competitor, LOCK_SH | LOCK_NB))->toBeTrue();
        flock($competitor, LOCK_UN);

        return $disk->get($path);
    });
    Storage::swap($proxy);
    try {
        $snapshot = (new SubjectHierarchyCacheService)->readSnapshot($file);
        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($competitor, LOCK_UN);
        expect(json_decode($snapshot['hierarchy'], true)['source_hash'])->toBe(hash('sha256', $snapshot['source']));
    } finally {
        Storage::swap($manager);
        fclose($competitor);
    }
});

it('releases the snapshot lock when reading fails', function (): void {
    unitHierarchyGraph([rawHierarchyNode('root')]);
    $file = 'gcmd-platforms.json';
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $competitor = fopen($disk->path('subject-hierarchies/locks/'.hash('sha256', $file).'.lock'), 'c');
    $proxy = Mockery::mock($manager)->shouldAllowMockingProtectedMethods();
    $proxy->shouldReceive('get')->once()->andThrow(new RuntimeException('Read failed.'));
    Storage::swap($proxy);
    try {
        expect(fn () => (new SubjectHierarchyCacheService)->readSnapshot($file))->toThrow(RuntimeException::class, 'Read failed.');
        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($competitor, LOCK_UN);
    } finally {
        Storage::swap($manager);
        fclose($competitor);
    }
});

it('preserves the complete previous snapshot when publishing either cache fails', function (string $failedCache, string $operation, bool $throws): void {
    unitHierarchyGraph([rawHierarchyNode('root'), rawHierarchyNode('old', ['root'])]);
    $file = 'gcmd-platforms.json';
    $cache = new SubjectHierarchyCacheService;
    $before = $cache->readSnapshot($file);
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $competitor = fopen($disk->path('subject-hierarchies/locks/'.hash('sha256', $file).'.lock'), 'c');
    $failedFile = $failedCache === 'hierarchy' ? 'subject-hierarchies/'.$file : $file;
    $proxy = Mockery::mock($manager);
    $proxy->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($disk, $failedFile, $operation, $throws): bool {
        if ($operation === 'put' && str_starts_with($path, $failedFile.'.')) {
            if ($throws) {
                throw new RuntimeException('Cache write failed.');
            }

            return false;
        }

        return $disk->put($path, $contents);
    });
    $proxy->shouldReceive('move')->andReturnUsing(function (string $source, string $target) use ($disk, $failedFile, $operation, $throws, $competitor): bool {
        expect(flock($competitor, LOCK_SH | LOCK_NB))->toBeFalse()
            ->and(flock($competitor, LOCK_EX | LOCK_NB))->toBeFalse();
        if ($operation === 'move' && $target === $failedFile && str_ends_with($source, '.tmp')) {
            if ($throws) {
                throw new RuntimeException('Cache move failed.');
            }

            return false;
        }

        return $disk->move($source, $target);
    });
    Storage::swap($proxy);
    try {
        expect(fn () => $cache->publishFlat($file, 'new editor snapshot',
            [rawHierarchyNode('root'), rawHierarchyNode('new', ['root'])], 'Platforms', 'https://example.org/scheme'))
            ->toThrow(RuntimeException::class, $throws ? 'Cache '.($operation === 'put' ? 'write' : 'move').' failed.' : 'Could not publish the vocabulary cache.');
        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($competitor, LOCK_UN);
    } finally {
        Storage::swap($manager);
        fclose($competitor);
    }
    expect($cache->readSnapshot($file))->toBe($before);
    $vocabulary = app(SubjectHierarchyVocabularyService::class);
    $vocabulary->reset();
    expect($vocabulary->graph('Platforms')->subtree('https://example.org/root')['leaf_ids'])->toBe(['https://example.org/old'])
        ->and(array_filter(Storage::allFiles(), fn (string $path): bool => str_ends_with($path, '.tmp') || str_ends_with($path, '.bak')))->toBe([]);
})->with([
    'editor write returns false' => ['editor', 'put', false],
    'editor write throws' => ['editor', 'put', true],
    'editor move returns false' => ['editor', 'move', false],
    'editor move throws' => ['editor', 'move', true],
    'hierarchy write returns false' => ['hierarchy', 'put', false],
    'hierarchy write throws' => ['hierarchy', 'put', true],
    'hierarchy move returns false' => ['hierarchy', 'move', false],
    'hierarchy move throws' => ['hierarchy', 'move', true],
]);

it('removes a newly published hierarchy when the first editor cache publication fails', function (bool $hasLegacyCache): void {
    $file = 'gcmd-platforms.json';
    if ($hasLegacyCache) {
        Storage::put($file, 'legacy editor snapshot');
    }
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $proxy = Mockery::mock($manager);
    $proxy->shouldReceive('move')->andReturnUsing(fn (string $source, string $target): bool => $target === $file && str_ends_with($source, '.tmp') ? false : $disk->move($source, $target));
    Storage::swap($proxy);
    try {
        expect(fn () => (new SubjectHierarchyCacheService)->publishFlat($file, 'new editor snapshot',
            [rawHierarchyNode('root')], 'Platforms', 'https://example.org/scheme'))
            ->toThrow(RuntimeException::class, 'Could not publish the vocabulary cache.');
    } finally {
        Storage::swap($manager);
    }
    expect(Storage::exists('subject-hierarchies/'.$file))->toBeFalse()
        ->and(Storage::exists($file))->toBe($hasLegacyCache)
        ->and(Storage::get($file))->toBe($hasLegacyCache ? 'legacy editor snapshot' : null)
        ->and(array_filter(Storage::allFiles(), fn (string $path): bool => str_ends_with($path, '.tmp') || str_ends_with($path, '.bak')))->toBe([]);
})->with([true, false]);

it('aborts before replacing either cache when preparing a backup fails', function (string $failedCache, bool $throws): void {
    unitHierarchyGraph([rawHierarchyNode('root'), rawHierarchyNode('old', ['root'])]);
    $file = 'gcmd-platforms.json';
    $cache = new SubjectHierarchyCacheService;
    $before = $cache->readSnapshot($file);
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $failedFile = $failedCache === 'hierarchy' ? 'subject-hierarchies/'.$file : $file;
    $proxy = Mockery::mock($manager);
    $proxy->shouldReceive('copy')->andReturnUsing(function (string $source, string $target) use ($disk, $failedFile, $throws): bool {
        if ($source === $failedFile) {
            $disk->put($target, 'partial backup');
            if ($throws) {
                throw new RuntimeException('Backup copy failed.');
            }

            return false;
        }

        return $disk->copy($source, $target);
    });
    $proxy->shouldReceive('move')->never();
    Storage::swap($proxy);
    try {
        expect(fn () => $cache->publishFlat($file, 'new editor snapshot',
            [rawHierarchyNode('root'), rawHierarchyNode('new', ['root'])], 'Platforms', 'https://example.org/scheme'))
            ->toThrow(RuntimeException::class, $throws ? 'Backup copy failed.' : 'Could not back up the vocabulary cache.');
    } finally {
        Storage::swap($manager);
    }
    expect($cache->readSnapshot($file))->toBe($before)
        ->and(array_filter(Storage::allFiles(), fn (string $path): bool => str_ends_with($path, '.tmp') || str_ends_with($path, '.bak')))->toBe([]);
})->with([
    'hierarchy backup returns false' => ['hierarchy', false],
    'hierarchy backup throws' => ['hierarchy', true],
    'editor backup returns false' => ['editor', false],
    'editor backup throws' => ['editor', true],
]);

it('retains the previous files and original error when rollback also fails', function (bool $throws): void {
    unitHierarchyGraph([rawHierarchyNode('root'), rawHierarchyNode('old', ['root'])]);
    $file = 'gcmd-platforms.json';
    $cache = new SubjectHierarchyCacheService;
    $before = $cache->readSnapshot($file);
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $competitor = fopen($disk->path('subject-hierarchies/locks/'.hash('sha256', $file).'.lock'), 'c');
    $publicationFailure = new RuntimeException('Editor cache write failed.');
    $proxy = Mockery::mock($manager);
    $proxy->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($disk, $file, $publicationFailure): bool {
        if (str_starts_with($path, $file.'.')) {
            throw $publicationFailure;
        }

        return $disk->put($path, $contents);
    });
    $proxy->shouldReceive('move')->andReturnUsing(function (string $source, string $target) use ($disk, $throws, $competitor): bool {
        expect(flock($competitor, LOCK_SH | LOCK_NB))->toBeFalse()
            ->and(flock($competitor, LOCK_EX | LOCK_NB))->toBeFalse();
        if (str_ends_with($source, '.bak')) {
            if ($throws) {
                throw new RuntimeException('Backup move failed.');
            }

            return false;
        }

        return $disk->move($source, $target);
    });
    Storage::swap($proxy);
    try {
        try {
            $cache->publishFlat($file, 'new editor snapshot',
                [rawHierarchyNode('root'), rawHierarchyNode('new', ['root'])], 'Platforms', 'https://example.org/scheme');
            $this->fail('Publication should fail when the editor cache cannot be written.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('Could not restore the previous vocabulary snapshot. Backup files are retained for recovery:')
                ->and($exception->getPrevious())->toBe($publicationFailure);
        }
        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($competitor, LOCK_UN);
    } finally {
        Storage::swap($manager);
        fclose($competitor);
    }
    $backups = array_filter(Storage::allFiles(), fn (string $path): bool => str_ends_with($path, '.bak'));
    expect($backups)->toHaveCount(2)
        ->and(array_map(fn (string $path): ?string => Storage::get($path), $backups))->toContain($before['hierarchy'], $before['source'])
        ->and(array_filter(Storage::allFiles(), fn (string $path): bool => str_ends_with($path, '.tmp')))->toBe([]);
})->with([true, false]);

it('rejects cycles, missing references, excessive depth and invalid identities before replacing valid caches', function (string $invalid): void {
    unitHierarchyGraph([rawHierarchyNode('root'), rawHierarchyNode('leaf', ['root'])]);
    $before = Storage::get('subject-hierarchies/gcmd-platforms.json');
    $nodes = match ($invalid) {
        'cycle' => [rawHierarchyNode('one', ['two']), rawHierarchyNode('two', ['one'])],
        'missing parent' => [rawHierarchyNode('one', ['missing'])],
        'empty' => [],
        'invalid id' => [['id' => 'not-a-uri', 'text' => 'Bad']],
        'inconsistent duplicate' => [rawHierarchyNode('one'), rawHierarchyNode('one', label: 'Different')],
        'depth' => array_map(fn (int $i): array => rawHierarchyNode(sprintf('%03d', $i), $i > 0 ? [sprintf('%03d', $i - 1)] : []), range(0, 64)),
    };
    expect(fn () => (new SubjectHierarchyCacheService)->publishFlat('gcmd-platforms.json', 'changed', $nodes, 'Platforms', 'https://example.org/scheme'))->toThrow(RuntimeException::class)
        ->and(Storage::get('subject-hierarchies/gcmd-platforms.json'))->toBe($before)
        ->and(Storage::get('gcmd-platforms.json'))->toBe('{"data":[]}');
})->with(['cycle', 'missing parent', 'empty', 'invalid id', 'inconsistent duplicate', 'depth']);

it('merges repeated concepts in native trees and excludes empty identity navigation groups', function (): void {
    $node = static fn (string $id, array $children = []): array => ['id' => $id === '' ? '' : 'https://example.org/'.$id,
        'text' => $id ?: 'Navigation', 'scheme' => 'Platforms', 'schemeURI' => 'https://example.org/scheme', 'children' => $children];
    $tree = [$node('', [$node('one', [$node('leaf')]), $node('two', [$node('leaf')])])];
    (new SubjectHierarchyCacheService)->publishTree('gcmd-platforms.json', 'tree', $tree);
    $graph = app(SubjectHierarchyVocabularyService::class)->graph('Platforms');
    expect($graph->concepts())->toHaveCount(3)
        ->and($graph->concept('https://example.org/leaf')['parents'])->toBe(['https://example.org/one', 'https://example.org/two'])
        ->and($graph->concept('https://example.org/one')['parents'])->toBe([]);
});

it('rejects malformed cache envelopes and concepts', function (string $variant): void {
    unitHierarchyGraph([rawHierarchyNode('root')]);
    $file = 'subject-hierarchies/gcmd-platforms.json';
    $payload = json_decode(Storage::get($file), true);
    match ($variant) {
        'version' => $payload['schema_version'] = 0,
        'incomplete' => $payload['complete'] = false,
        'hash' => $payload['source_hash'] = 'wrong',
        'file' => $payload['source_file'] = 'different.json',
        'scheme' => $payload['concepts'][0]['scheme'] = 'Instruments',
        'id' => $payload['concepts'][0]['id'] = 'invalid',
        'language' => $payload['concepts'][0]['language'] = null,
        'scheme uri' => $payload['concepts'][0]['scheme_uri'] = '',
        'parent' => $payload['concepts'][0]['parents'] = [123],
        'duplicate' => $payload['concepts'][] = $payload['concepts'][0],
    };
    Storage::put($file, json_encode($payload));
    $service = app(SubjectHierarchyVocabularyService::class);
    $service->reset();
    expect(fn () => $service->graph('Platforms'))->toThrow(RuntimeException::class);
})->with(['version', 'incomplete', 'hash', 'file', 'scheme', 'id', 'language', 'scheme uri', 'parent', 'duplicate']);

it('resolves ambiguous labels only with a unique full path and rejects contradictory evidence', function (): void {
    $graph = unitHierarchyGraph([rawHierarchyNode('left'), rawHierarchyNode('right'),
        rawHierarchyNode('a', ['left'], 'Same'), rawHierarchyNode('b', ['right'], 'Same'),
        [...rawHierarchyNode('other'), 'notation' => 'X']]);
    $service = app(SubjectHierarchyVocabularyService::class);
    expect($service->resolve(new Subject(['value' => 'Same', 'subject_scheme' => 'Platforms']), $graph))->toBeNull()
        ->and($service->resolve(new Subject(['value' => 'Same', 'subject_scheme' => 'Platforms', 'breadcrumb_path' => 'Right > Same']), $graph))->toBe('https://example.org/b')
        ->and($service->resolve(new Subject(['value' => 'Same', 'subject_scheme' => 'Platforms', 'value_uri' => 'https://example.org/other']), $graph))->toBeNull()
        ->and($service->resolve(new Subject(['value' => 'Same', 'subject_scheme' => 'Platforms', 'value_uri' => 'https://example.org/a', 'classification_code' => 'X']), $graph))->toBeNull();
});

it('normalizes GCMD URI aliases and known legacy MSL concept aliases', function (): void {
    $uuid = '11111111-1111-4111-8111-111111111111';
    $graph = unitHierarchyGraph([['id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/'.$uuid, 'text' => 'Example']]);
    $service = app(SubjectHierarchyVocabularyService::class);
    expect($service->resolve(new Subject(['subject_scheme' => 'GCMD Platforms', 'value' => 'Example',
        'value_uri' => 'https://cmr.earthdata.nasa.gov/kms/concept/'.$uuid]), $graph))->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/'.$uuid);
    // Use a real alias from the project's mapping, independently of its spelling.
    $reflection = new ReflectionClass(PortalSubjectNormalizer::class);
    $mappings = $reflection->getConstant('LEGACY_MSL_CURRENT_NODE_URIS');
    $scheme = array_key_first($mappings);
    $legacy = array_key_first($mappings[$scheme]);
    $current = $mappings[$scheme][$legacy];
    (new SubjectHierarchyCacheService)->publishFlat('msl-vocabulary.json', 'msl', [['id' => $current, 'text' => 'Material']], 'EPOS MSL vocabulary', 'https://epos-msl.uu.nl/voc');
    $msl = new Subject(['subject_scheme' => $scheme, 'value_uri' => $legacy, 'value' => 'Material']);
    expect($service->scheme($msl))->toBe('EPOS MSL vocabulary')
        ->and($service->resolve($msl, $service->graph('EPOS MSL vocabulary')))->toBe($current);
});

it('reads real GEMET concept relations with relative RDF identities instead of group membership', function (): void {
    $groups = ['group' => [
        ['uri' => 'http://www.eionet.europa.eu/gemet/concept/100', 'label' => 'Air', 'definition' => 'Air definition'],
        ['uri' => 'http://www.eionet.europa.eu/gemet/concept/101', 'label' => 'Pollution', 'definition' => 'Pollution definition'],
    ]];
    $rdf = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:skos="http://www.w3.org/2004/02/skos/core#" xml:base="http://www.eionet.europa.eu/gemet/">
        <skos:Concept rdf:about="concept/100"><skos:narrower rdf:resource="concept/101" /></skos:Concept>
        <skos:Concept rdf:about="concept/101"><skos:related rdf:resource="concept/100" /></skos:Concept></rdf:RDF>';
    $nodes = (new GemetConceptHierarchyParser)->concepts($rdf, $groups);
    (new SubjectHierarchyCacheService)->publishFlat('gemet-thesaurus.json', 'gemet', $nodes, 'GEMET', 'http://www.eionet.europa.eu/gemet/concept/');
    expect(app(SubjectHierarchyVocabularyService::class)->graph('GEMET')->subtree('http://www.eionet.europa.eu/gemet/concept/100')['leaf_ids'])
        ->toBe(['http://www.eionet.europa.eu/gemet/concept/101']);
});

it('rejects malformed or incomplete GEMET exports', function (string $rdf): void {
    $groups = ['group' => [['uri' => 'http://www.eionet.europa.eu/gemet/concept/100', 'label' => 'Air', 'definition' => '']]];
    expect(fn () => (new GemetConceptHierarchyParser)->concepts($rdf, $groups))->toThrow(RuntimeException::class);
})->with([
    'not xml',
    '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" />',
    '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:skos="http://www.w3.org/2004/02/skos/core#"><skos:Concept rdf:about="concept/100"><skos:broader rdf:resource="concept/999" /></skos:Concept></rdf:RDF>',
]);

it('retains every broader relation in GCMD and EuroSciVoc RDF parsers', function (): void {
    $scheme = 'https://example.org/scheme';
    $rdf = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:skos="http://www.w3.org/2004/02/skos/core#"><skos:Concept rdf:about="https://example.org/leaf"><skos:prefLabel xml:lang="en">Leaf</skos:prefLabel><skos:inScheme rdf:resource="'.$scheme.'"/><skos:broader rdf:resource="https://example.org/one"/><skos:broader rdf:resource="https://example.org/two"/></skos:Concept></rdf:RDF>';
    foreach ([(new GcmdVocabularyParser)->extractConcepts($rdf), (new EuroSciVocParser)->extractConcepts($rdf, $scheme)] as $concepts) {
        expect($concepts[0]['broaderIds'])->toBe(['https://example.org/one', 'https://example.org/two']);
    }
});

it('retains every broader relation in both ARDC parser formats', function (): void {
    $items = [['_about' => 'https://example.org/leaf', 'prefLabel' => ['_value' => 'Leaf', '_lang' => 'en'],
        'broader' => [['_about' => 'https://example.org/one'], ['_about' => 'https://example.org/two']]]];
    foreach ([(new ChronostratVocabularyParser)->extractConcepts($items), (new AnalyticalMethodsVocabularyParser)->extractConcepts($items)] as $concepts) {
        expect($concepts[0]['broaderIds'])->toBe(['https://example.org/one', 'https://example.org/two']);
    }
});
