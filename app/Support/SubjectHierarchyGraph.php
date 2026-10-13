<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A concept graph, independent of the vocabulary's navigation groups.
 *
 * @phpstan-type Concept array{id: string, label: string, language: string, scheme: string, scheme_uri: string, classification_code: string|null, description: string, parents: list<string>, selectable: bool}
 * @phpstan-type PresentedConcept array{id: string, label: string, language: string, scheme: string, scheme_uri: string, classification_code: string|null, description: string, parents: list<string>, selectable: bool, children: list<string>, path: string}
 */
final class SubjectHierarchyGraph
{
    /** @var array<string, list<string>> */
    private array $children = [];

    /** @var array<string, string> */
    private array $paths = [];

    /** @var array<string, int> */
    private array $depths = [];

    /** @var array<string, list<string>> */
    private array $labels = [];

    /** @var array<string, list<string>> */
    private array $notations = [];

    /** @param array<string, Concept> $concepts */
    public function __construct(public readonly string $scheme, private readonly array $concepts)
    {
        if ($concepts === []) {
            throw new RuntimeException('The hierarchy contains no concepts.');
        }

        foreach ($concepts as $id => $concept) {
            $this->labels[mb_strtolower(trim($concept['label']))][] = $id;
            if ($concept['classification_code'] !== null) {
                $this->notations[$concept['classification_code']][] = $id;
            }
            foreach ($concept['parents'] as $parent) {
                if (! isset($concepts[$parent])) {
                    throw new RuntimeException('The hierarchy references a missing concept: '.$parent);
                }
                $this->children[$parent][] = $id;
            }
        }

        foreach ($this->children as &$children) {
            sort($children, SORT_STRING);
        }
        unset($children);

        foreach (array_keys($concepts) as $id) {
            $this->path($id);
        }
    }

    /** @return Concept|null */
    public function concept(string $id): ?array
    {
        return $this->concepts[$id] ?? null;
    }

    /** @return list<Concept> */
    public function concepts(): array
    {
        return array_values($this->concepts);
    }

    /** @return list<string> */
    public function candidates(string $label, ?string $notation): array
    {
        $labels = $this->labels[$label] ?? [];
        $notations = $this->notations[$notation ?? ''] ?? [];

        return $labels !== [] && $notations !== [] ? array_values(array_intersect($labels, $notations)) : ($labels ?: $notations);
    }

    /** @return list<string> */
    public function labelCandidates(string $label): array
    {
        return $this->labels[$label] ?? [];
    }

    /** @return list<string> */
    public function notationCandidates(?string $notation): array
    {
        return $this->notations[$notation ?? ''] ?? [];
    }

    public function conceptPath(string $id): string
    {
        return $this->paths[$id];
    }

    /** @param array<string, true> $ancestors */
    private function path(string $id, array $ancestors = []): string
    {
        if (isset($ancestors[$id]) || count($ancestors) >= 64) {
            throw new RuntimeException('The hierarchy contains a cycle or exceeds 64 levels.');
        }
        if (isset($this->paths[$id])) {
            return $this->paths[$id];
        }

        $ancestors[$id] = true;
        $paths = [];
        foreach ($this->concepts[$id]['parents'] as $parent) {
            $paths[] = $this->path($parent, $ancestors);
        }
        sort($paths, SORT_STRING);
        $this->depths[$id] = 1 + max([0, ...array_map(fn (string $parent): int => $this->depths[$parent], $this->concepts[$id]['parents'])]);
        if ($this->depths[$id] > 64) {
            throw new RuntimeException('The hierarchy exceeds 64 levels.');
        }

        return $this->paths[$id] = ($paths === [] ? '' : $paths[0].' > ').$this->concepts[$id]['label'];
    }

    /** @return array{nodes: list<PresentedConcept>, leaf_ids: list<string>} */
    public function subtree(string $id): array
    {
        $pending = [$id];
        $visited = [];
        $nodes = [];
        $leaves = [];

        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $concept = $this->concepts[$current];
            $children = $this->children[$current] ?? [];
            $nodes[] = [...$concept, 'children' => $children, 'path' => $this->paths[$current]];
            if ($current !== $id && $children === [] && $concept['selectable']) {
                $leaves[] = $current;
            }
            array_push($pending, ...$children);
        }

        usort($nodes, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        sort($leaves, SORT_STRING);

        return ['nodes' => $nodes, 'leaf_ids' => $leaves];
    }
}
