<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class CgiSimpleLithologyVocabularyParser
{
    /**
     * Validate a previously generated local payload before it is exposed or
     * used as the last-known-good update baseline.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validatePayload(
        array $payload,
        int $minimumConcepts,
        int $maximumConcepts,
        int $maximumDepth,
    ): void {
        $schemeName = (string) config('simple_lithology.scheme_name');
        $schemeUri = (string) config('simple_lithology.scheme_uri');
        $collectionUri = rtrim((string) config('simple_lithology.collection_uri'), '/');
        $conceptPrefix = $collectionUri.'/';
        $data = $payload['data'] ?? null;
        $source = $payload['source'] ?? null;

        if (($payload['schemaVersion'] ?? null) !== 1
            || ! is_string($payload['lastUpdated'] ?? null)
            || ! is_int($payload['total'] ?? null)
            || ! is_int($payload['pathCount'] ?? null)
            || ! is_array($data)
            || ! array_is_list($data)
            || ! is_array($source)
            || ($source['endpoint'] ?? null) !== config('simple_lithology.endpoint')
            || ($source['schemeURI'] ?? null) !== $schemeUri
            || ($source['collectionURI'] ?? null) !== $collectionUri
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($source['sha256'] ?? '')) !== 1
        ) {
            throw new RuntimeException('The local CGI Simple Lithology vocabulary has an invalid metadata envelope.');
        }

        try {
            new \DateTimeImmutable($payload['lastUpdated']);
        } catch (\Throwable) {
            throw new RuntimeException('The local CGI Simple Lithology vocabulary has an invalid update timestamp.');
        }

        /** @var array<string, array{id: string, text: string, description: string, parents: array<string, true>}> $concepts */
        $concepts = [];
        $pathCount = 0;

        $visit = function (array $node, ?string $parentId, int $depth, array $ancestors) use (
            &$visit,
            &$concepts,
            &$pathCount,
            $maximumDepth,
            $conceptPrefix,
            $schemeName,
            $schemeUri,
        ): void {
            if ($depth > $maximumDepth) {
                throw new RuntimeException("Simple Lithology exceeds the maximum hierarchy depth of {$maximumDepth}.");
            }

            $id = $node['id'] ?? null;
            $text = $node['text'] ?? null;
            $description = $node['description'] ?? null;
            $children = $node['children'] ?? null;
            if (! is_string($id)
                || ! str_starts_with($id, $conceptPrefix)
                || ! filter_var($id, FILTER_VALIDATE_URL)
                || ! is_string($text)
                || trim($text) === ''
                || ! is_string($description)
                || ($node['language'] ?? null) !== 'en'
                || ($node['scheme'] ?? null) !== $schemeName
                || ($node['schemeURI'] ?? null) !== $schemeUri
                || ! is_array($children)
                || ! array_is_list($children)
            ) {
                throw new RuntimeException('The local CGI Simple Lithology vocabulary contains an invalid concept node.');
            }

            if (isset($ancestors[$id])) {
                throw new RuntimeException("Simple Lithology contains a cycle at {$id}.");
            }
            $ancestors[$id] = true;
            $pathCount++;

            $normalizedText = trim($text);
            $normalizedDescription = trim($description);
            if (isset($concepts[$id])
                && ($concepts[$id]['text'] !== $normalizedText
                    || $concepts[$id]['description'] !== $normalizedDescription)
            ) {
                throw new RuntimeException("Simple Lithology concept {$id} is inconsistent across hierarchy paths.");
            }

            $concepts[$id] ??= [
                'id' => $id,
                'text' => $normalizedText,
                'description' => $normalizedDescription,
                'parents' => [],
            ];
            if ($parentId !== null) {
                $concepts[$id]['parents'][$parentId] = true;
            }

            $directChildren = [];
            foreach ($children as $child) {
                if (! is_array($child)) {
                    throw new RuntimeException('The local CGI Simple Lithology vocabulary contains an invalid child node.');
                }

                $childId = $child['id'] ?? null;
                if (! is_string($childId) || isset($directChildren[$childId])) {
                    throw new RuntimeException("Simple Lithology contains a duplicate or invalid child below {$id}.");
                }
                $directChildren[$childId] = true;
                $visit($child, $id, $depth + 1, $ancestors);
            }
        };

        foreach ($data as $root) {
            if (! is_array($root)) {
                throw new RuntimeException('The local CGI Simple Lithology vocabulary contains an invalid root node.');
            }
            $visit($root, null, 1, []);
        }

        $conceptCount = count($concepts);
        if ($conceptCount < $minimumConcepts || $conceptCount > $maximumConcepts) {
            throw new RuntimeException(
                "Simple Lithology concept count {$conceptCount} is outside the allowed range {$minimumConcepts}-{$maximumConcepts}."
            );
        }
        if ($payload['total'] !== $conceptCount || $payload['pathCount'] !== $pathCount) {
            throw new RuntimeException('The local CGI Simple Lithology vocabulary contains inconsistent concept or path counts.');
        }

        /** @var array<string, array{id: string, text: string, description: string, parents: list<string>}> $normalized */
        $normalized = [];
        foreach ($concepts as $id => $concept) {
            $parents = array_keys($concept['parents']);
            sort($parents, SORT_STRING);
            $normalized[$id] = [
                'id' => $id,
                'text' => $concept['text'],
                'description' => $concept['description'],
                'parents' => $parents,
            ];
        }
        ksort($normalized, SORT_STRING);

        [$children, $roots] = $this->hierarchy($normalized);
        $this->assertAcyclicAndReachable($normalized, $children, $roots, $maximumDepth);
        $expectedTree = array_map(
            fn (string $root): array => $this->buildNode($root, $normalized, $children, $schemeName, $schemeUri, 1, $maximumDepth),
            $roots,
        );
        if ($expectedTree !== $data) {
            throw new RuntimeException('The local CGI Simple Lithology hierarchy is incomplete or not deterministic.');
        }

        $canonical = array_values($normalized);
        $canonicalJson = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (! hash_equals((string) $source['sha256'], hash('sha256', $canonicalJson))) {
            throw new RuntimeException('The local CGI Simple Lithology vocabulary content hash is invalid.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $bindings
     * @return array<string, mixed>
     */
    public function buildPayload(
        array $bindings,
        ?string $dateModified,
        int $minimumConcepts,
        int $maximumConcepts,
        int $maximumDepth,
    ): array {
        $schemeName = (string) config('simple_lithology.scheme_name');
        $schemeUri = (string) config('simple_lithology.scheme_uri');
        $collectionUri = rtrim((string) config('simple_lithology.collection_uri'), '/');
        $conceptPrefix = $collectionUri.'/';

        /** @var array<string, array{labels: array<string, string>, definitions: array<string, string>, parents: array<string, true>}> $concepts */
        $concepts = [];

        foreach ($bindings as $binding) {
            $conceptUri = $this->bindingValue($binding, 'concept');
            $label = $this->bindingValue($binding, 'prefLabel');
            if ($conceptUri === null || $label === null) {
                continue;
            }

            if (! str_starts_with($conceptUri, $conceptPrefix) || ! filter_var($conceptUri, FILTER_VALIDATE_URL)) {
                throw new RuntimeException("Unexpected Simple Lithology concept URI: {$conceptUri}");
            }

            $concepts[$conceptUri] ??= [
                'labels' => [],
                'definitions' => [],
                'parents' => [],
            ];

            $labelLanguage = $this->bindingLanguage($binding, 'prefLabel');
            $concepts[$conceptUri]['labels'][$labelLanguage] = trim($label);

            $definition = $this->bindingValue($binding, 'definition');
            if ($definition !== null) {
                $definitionLanguage = $this->bindingLanguage($binding, 'definition');
                $concepts[$conceptUri]['definitions'][$definitionLanguage] = trim($definition);
            }

            $broader = $this->bindingValue($binding, 'broader');
            if ($broader !== null) {
                if ($broader !== $collectionUri && ! str_starts_with($broader, $conceptPrefix)) {
                    throw new RuntimeException("Unexpected Simple Lithology broader URI: {$broader}");
                }

                if ($broader !== $collectionUri) {
                    $concepts[$conceptUri]['parents'][$broader] = true;
                }
            }
        }

        $conceptCount = count($concepts);
        if ($conceptCount < $minimumConcepts || $conceptCount > $maximumConcepts) {
            throw new RuntimeException(
                "Simple Lithology concept count {$conceptCount} is outside the allowed range {$minimumConcepts}-{$maximumConcepts}."
            );
        }

        /** @var array<string, array{id: string, text: string, description: string, parents: list<string>}> $normalized */
        $normalized = [];
        foreach ($concepts as $id => $concept) {
            $text = $this->preferredText($concept['labels']);
            if ($text === null) {
                throw new RuntimeException("Simple Lithology concept {$id} has no English or language-neutral preferred label.");
            }

            $parents = array_values(array_filter(
                array_keys($concept['parents']),
                static fn (string $parent): bool => isset($concepts[$parent]),
            ));
            sort($parents, SORT_STRING);

            $normalized[$id] = [
                'id' => $id,
                'text' => $text,
                'description' => $this->preferredText($concept['definitions']) ?? '',
                'parents' => $parents,
            ];
        }
        ksort($normalized, SORT_STRING);

        [$children, $roots] = $this->hierarchy($normalized);

        $this->assertAcyclicAndReachable($normalized, $children, $roots, $maximumDepth);

        $tree = array_map(
            fn (string $root): array => $this->buildNode($root, $normalized, $children, $schemeName, $schemeUri, 1, $maximumDepth),
            $roots,
        );
        $pathCount = $this->countNodes($tree);

        $canonical = [];
        foreach ($normalized as $concept) {
            $canonical[] = [
                'id' => $concept['id'],
                'text' => $concept['text'],
                'description' => $concept['description'],
                'parents' => $concept['parents'],
            ];
        }

        $canonicalJson = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $payload = [
            'schemaVersion' => 1,
            'lastUpdated' => now()->toIso8601String(),
            'total' => $conceptCount,
            'pathCount' => $pathCount,
            'source' => [
                'provider' => 'Commission for the Management and Application of Geoscience Information',
                'endpoint' => (string) config('simple_lithology.endpoint'),
                'schemeURI' => $schemeUri,
                'collectionURI' => $collectionUri,
                'dateModified' => $dateModified,
                'sha256' => hash('sha256', $canonicalJson),
            ],
            'data' => $tree,
        ];

        $this->validatePayload($payload, $minimumConcepts, $maximumConcepts, $maximumDepth);

        return $payload;
    }

    /**
     * @param  array<string, array{id: string, text: string, description: string, parents: list<string>}>  $concepts
     * @param  array<string, list<string>>  $children
     * @param  list<string>  $roots
     */
    private function assertAcyclicAndReachable(array $concepts, array $children, array $roots, int $maximumDepth): void
    {
        /** @var array<string, int> $states */
        $states = [];
        /** @var array<string, true> $reachable */
        $reachable = [];

        $visit = function (string $id, int $depth) use (&$visit, &$states, &$reachable, $children, $maximumDepth): void {
            if ($depth > $maximumDepth) {
                throw new RuntimeException("Simple Lithology exceeds the maximum hierarchy depth of {$maximumDepth}.");
            }

            if (($states[$id] ?? 0) === 1) {
                throw new RuntimeException("Simple Lithology contains a cycle at {$id}.");
            }
            if (($states[$id] ?? 0) === 2) {
                $reachable[$id] = true;

                return;
            }

            $states[$id] = 1;
            $reachable[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $visit($child, $depth + 1);
            }
            $states[$id] = 2;
        };

        foreach ($roots as $root) {
            $visit($root, 1);
        }

        if (count($reachable) !== count($concepts)) {
            throw new RuntimeException('Simple Lithology contains concepts that are not reachable from a root concept.');
        }
    }

    /**
     * @param  array<string, array{id: string, text: string, description: string, parents: list<string>}>  $normalized
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function hierarchy(array $normalized): array
    {
        $children = [];
        $roots = [];
        foreach ($normalized as $id => $concept) {
            if ($concept['parents'] === []) {
                $roots[] = $id;
            }

            foreach ($concept['parents'] as $parent) {
                if (! isset($normalized[$parent])) {
                    throw new RuntimeException("Simple Lithology concept {$id} references a missing parent {$parent}.");
                }
                $children[$parent] ??= [];
                $children[$parent][] = $id;
            }
        }

        if ($roots === []) {
            throw new RuntimeException('Simple Lithology contains no root concept.');
        }

        foreach ($children as &$childIds) {
            usort($childIds, static function (string $left, string $right) use ($normalized): int {
                return [$normalized[$left]['text'], $left] <=> [$normalized[$right]['text'], $right];
            });
        }
        unset($childIds);
        usort($roots, static function (string $left, string $right) use ($normalized): int {
            return [$normalized[$left]['text'], $left] <=> [$normalized[$right]['text'], $right];
        });

        return [$children, $roots];
    }

    /**
     * @param  array<string, array{id: string, text: string, description: string, parents: list<string>}>  $concepts
     * @param  array<string, list<string>>  $children
     * @return array<string, mixed>
     */
    private function buildNode(
        string $id,
        array $concepts,
        array $children,
        string $schemeName,
        string $schemeUri,
        int $depth,
        int $maximumDepth,
    ): array {
        if ($depth > $maximumDepth) {
            throw new RuntimeException("Simple Lithology exceeds the maximum hierarchy depth of {$maximumDepth}.");
        }

        $concept = $concepts[$id];

        return [
            'id' => $id,
            'text' => $concept['text'],
            'language' => 'en',
            'scheme' => $schemeName,
            'schemeURI' => $schemeUri,
            'description' => $concept['description'],
            'children' => array_map(
                fn (string $child): array => $this->buildNode(
                    $child,
                    $concepts,
                    $children,
                    $schemeName,
                    $schemeUri,
                    $depth + 1,
                    $maximumDepth,
                ),
                $children[$id] ?? [],
            ),
        ];
    }

    /** @param array<string, string> $values */
    private function preferredText(array $values): ?string
    {
        $preferredLanguages = ['en'];
        $regionalEnglishLanguages = array_values(array_filter(
            array_keys($values),
            static fn (string $language): bool => str_starts_with($language, 'en-'),
        ));
        sort($regionalEnglishLanguages, SORT_STRING);

        foreach ([...$preferredLanguages, ...$regionalEnglishLanguages, ''] as $language) {
            $value = trim($values[$language] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $binding */
    private function bindingValue(array $binding, string $key): ?string
    {
        $value = $binding[$key]['value'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @param array<string, mixed> $binding */
    private function bindingLanguage(array $binding, string $key): string
    {
        $language = $binding[$key]['xml:lang'] ?? $binding[$key]['lang'] ?? '';

        return is_string($language) ? mb_strtolower(trim($language)) : '';
    }

    /** @param array<array-key, mixed> $nodes */
    private function countNodes(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $count++;
            $children = $node['children'] ?? [];
            if (is_array($children)) {
                $count += $this->countNodes($children);
            }
        }

        return $count;
    }
}
