<?php

declare(strict_types=1);

namespace App\Services\SubjectHierarchy;

use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectHierarchyGraph;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** @phpstan-import-type Concept from SubjectHierarchyGraph */
final class SubjectHierarchyCacheService
{
    /** @return array{hierarchy: string, source: string} */
    public function readSnapshot(string $file): array
    {
        return $this->withSnapshotLock($file, LOCK_SH, function () use ($file): array {
            if (! Storage::exists($file) || ! Storage::exists('subject-hierarchies/'.$file)) {
                throw new RuntimeException('The local hierarchy is missing. Update this vocabulary in Editor Settings.');
            }
            $hierarchy = Storage::get('subject-hierarchies/'.$file);
            $source = Storage::get($file);
            if (! is_string($hierarchy) || ! is_string($source)) {
                throw new RuntimeException('Could not read the vocabulary snapshot.');
            }

            return ['hierarchy' => $hierarchy, 'source' => $source];
        });
    }

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
        $hierarchy = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->withSnapshotLock($file, LOCK_EX, function () use ($file, $json, $hierarchy): void {
            $this->replaceSnapshot(['subject-hierarchies/'.$file => $hierarchy, $file => $json]);
        });
    }

    /** @param array<string, string> $files */
    private function replaceSnapshot(array $files): void
    {
        $backups = [];
        $rollbackFailed = false;
        try {
            // Prepare every backup before changing either published file.
            foreach (array_keys($files) as $file) {
                $backup = Storage::exists($file) ? $file.'.'.Str::uuid().'.bak' : null;
                $backups[$file] = $backup;
                if ($backup !== null && ! Storage::copy($file, $backup)) {
                    throw new RuntimeException('Could not back up the vocabulary cache.');
                }
            }
            try {
                foreach ($files as $file => $json) {
                    $this->replace($file, $json);
                }
            } catch (Throwable $exception) {
                try {
                    foreach ($backups as $file => $backup) {
                        // Rename existing backups so rollback also works after a failed write.
                        $restored = $backup === null ? Storage::delete($file) : Storage::move($backup, $file);
                        if (! $restored) {
                            throw new RuntimeException('Could not restore '.$file.'.');
                        }
                    }
                } catch (Throwable $rollbackException) {
                    $rollbackFailed = true;
                    throw new RuntimeException('Could not restore the previous vocabulary snapshot. Backup files are retained for recovery: '.$rollbackException->getMessage(), 0, $exception);
                }
                throw $exception;
            }
        } finally {
            if (! $rollbackFailed) {
                foreach ($backups as $backup) {
                    if ($backup !== null) {
                        Storage::delete($backup);
                    }
                }
            }
        }
    }

    /**
     * Readers and publishers use the same persistent lock file. Do not unlink it:
     * another process may already hold an open handle to its inode.
     *
     * @template T
     *
     * @param  int-mask<LOCK_SH, LOCK_EX>  $mode
     * @param  Closure(): T  $operation
     * @return T
     */
    private function withSnapshotLock(string $file, int $mode, Closure $operation): mixed
    {
        Storage::makeDirectory('subject-hierarchies/locks');
        $handle = fopen(Storage::path('subject-hierarchies/locks/'.hash('sha256', $file).'.lock'), 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open the vocabulary snapshot lock.');
        }
        try {
            if (! flock($handle, $mode)) {
                throw new RuntimeException('Could not acquire the vocabulary snapshot lock.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
