<?php

declare(strict_types=1);

namespace App\Services\SubjectHierarchy;

use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectHierarchyGraph;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** @phpstan-import-type Concept from SubjectHierarchyGraph */
final class SubjectHierarchyCacheService
{
    /**
     * Preserve all source relations alongside the unchanged editor payload.
     *
     * @param  array<int, array<string, mixed>>  $concepts
     */
    public function publishFlat(string $file, string $json, array $concepts, string $scheme, string $schemeUri): void
    {
        $nodes = [];
        foreach ($concepts as $concept) {
            $parents = $concept['broaderIds'] ?? [$concept['broaderId'] ?? null];
            $nodes[] = $this->node($concept, $scheme, $schemeUri, $this->uris($parents));
        }
        $this->publish($file, $json, $nodes);
    }

    /** @param array<int, array<string, mixed>> $concepts */
    public function validateFlat(array $concepts, string $scheme, string $schemeUri): void
    {
        $nodes = [];
        foreach ($concepts as $concept) {
            $node = $this->node($concept, $scheme, $schemeUri, $this->uris($concept['broaderIds'] ?? [$concept['broaderId'] ?? null]));
            if (isset($nodes[$node['id']])) {
                $node['parents'] = array_values(array_unique([...$nodes[$node['id']]['parents'], ...$node['parents']]));
            }
            $nodes[$node['id']] = $node;
        }
        new SubjectHierarchyGraph($scheme, $nodes);
    }

    /** @param array<int, array<string, mixed>> $tree */
    public function publishTree(string $file, string $json, array $tree): void
    {
        $nodes = [];
        $visit = function (array $node, ?string $parent = null, int $depth = 0) use (&$visit, &$nodes): void {
            if ($depth >= 64) {
                throw new RuntimeException('The source hierarchy exceeds 64 levels.');
            }
            $id = is_string($node['id'] ?? null) ? $node['id'] : '';
            $scheme = is_string($node['scheme'] ?? null) ? $node['scheme'] : '';
            $schemeUri = is_string($node['schemeURI'] ?? null) ? $node['schemeURI'] : '';
            if ($id !== '') {
                $nodes[] = $this->node($node, $scheme, $schemeUri, $parent === null ? [] : [$parent]);
            }
            foreach ($node['children'] ?? [] as $child) {
                if (! is_array($child)) {
                    throw new RuntimeException('The source hierarchy contains an invalid child.');
                }
                $visit($child, $id === '' ? null : $id, $depth + 1);
            }
        };
        foreach ($tree as $root) {
            $visit($root);
        }
        $this->publish($file, $json, $nodes);
    }

    /**
     * @return list<string>
     */
    public function uris(mixed $values): array
    {
        if (is_string($values)) {
            return trim($values) === '' ? [] : [trim($values)];
        }
        if (! is_array($values)) {
            return [];
        }
        if (isset($values['_about'])) {
            return $this->uris($values['_about']);
        }
        $uris = [];
        foreach ($values as $value) {
            array_push($uris, ...$this->uris($value));
        }
        $uris = array_values(array_unique($uris));
        sort($uris, SORT_STRING);

        return $uris;
    }

    /** @param array<string, mixed> $concept
     * @param  list<string>  $parents
     * @return Concept
     */
    private function node(array $concept, string $scheme, string $schemeUri, array $parents): array
    {
        $id = $concept['id'] ?? null;
        $label = $concept['text'] ?? null;
        if (! is_string($id) || ! filter_var($id, FILTER_VALIDATE_URL) || ! is_string($label) || trim($label) === '' || ! filter_var($schemeUri, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('The source hierarchy contains an invalid concept.');
        }

        return [
            'id' => $id,
            'label' => trim($label),
            'language' => is_string($concept['language'] ?? null) ? $concept['language'] : 'en',
            'scheme' => PortalSubjectNormalizer::normalizeScheme($scheme) ?? $scheme,
            'scheme_uri' => $schemeUri,
            'classification_code' => is_string($concept['notation'] ?? null) && $concept['notation'] !== '' ? $concept['notation'] : null,
            'description' => (string) ($concept['description'] ?? $concept['definition'] ?? ''),
            'parents' => $parents,
            'selectable' => ($concept['selectable'] ?? true) === true,
        ];
    }

    /** @param list<Concept> $nodes */
    private function publish(string $file, string $json, array $nodes): void
    {
        $indexed = [];
        foreach ($nodes as $node) {
            $id = $node['id'];
            if (isset($indexed[$id])) {
                $old = $indexed[$id];
                $parents = array_values(array_unique([...$old['parents'], ...$node['parents']]));
                sort($parents, SORT_STRING);
                $oldComparable = $old;
                $newComparable = $node;
                unset($oldComparable['parents'], $newComparable['parents']);
                if ($oldComparable !== $newComparable) {
                    throw new RuntimeException('A source concept is inconsistent across hierarchy paths.');
                }
                $node['parents'] = $parents;
            }
            $indexed[$id] = $node;
        }
        ksort($indexed, SORT_STRING);
        $scheme = array_first($indexed)['scheme'] ?? '';
        new SubjectHierarchyGraph($scheme, $indexed);
        $payload = [
            'schema_version' => 1,
            'source_file' => $file,
            'source_hash' => hash('sha256', $json),
            'complete' => true,
            'concepts' => array_values($indexed),
        ];
        $this->replace('subject-hierarchies/'.$file, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->replace($file, $json);
    }

    private function replace(string $file, string $json): void
    {
        $temporary = $file.'.'.Str::uuid().'.tmp';
        try {
            if (! Storage::put($temporary, $json) || ! Storage::move($temporary, $file)) {
                throw new RuntimeException('Could not publish the vocabulary cache.');
            }
        } finally {
            Storage::delete($temporary);
        }
    }
}
