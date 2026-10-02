<?php

declare(strict_types=1);

namespace App\Services\SubjectHierarchy;

use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use App\Support\GcmdUriHelper;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectHierarchyGraph;
use RuntimeException;

/**
 * @phpstan-import-type Concept from SubjectHierarchyGraph
 * @phpstan-import-type PresentedConcept from SubjectHierarchyGraph
 *
 * @phpstan-type CorrectionCase array{scheme: string, broader_id: string, broader_label: string, fingerprint: string, nodes: list<PresentedConcept>, leaf_ids: list<string>, existing_leaf_ids: list<string>, broader_subject_ids: list<int>, source_file: string}
 */
final class SubjectHierarchyVocabularyService
{
    /** @var array<string, SubjectHierarchyGraph> */
    private array $graphs = [];

    /** @var array<string, string|null> */
    private array $resolutions = [];

    public function __construct(private readonly SubjectVocabularyLookupService $lookup, private readonly SubjectHierarchyCacheService $cache) {}

    public function reset(): void
    {
        $this->graphs = [];
        $this->resetResolutions();
    }

    public function resetResolutions(): void
    {
        $this->resolutions = [];
    }

    public function graph(string $scheme): SubjectHierarchyGraph
    {
        $scheme = $this->lookup->normalizeSupportedScheme($scheme) ?? $scheme;
        if (isset($this->graphs[$scheme])) {
            return $this->graphs[$scheme];
        }
        $file = $this->lookup->localCacheFile($scheme);
        if ($file === null) {
            throw new RuntimeException('The local hierarchy is missing. Update this vocabulary in Editor Settings.');
        }
        $snapshot = $this->cache->readSnapshot($file);
        $source = $snapshot['source'];
        $payload = json_decode($snapshot['hierarchy'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['schema_version'] ?? null) !== 1 || ($payload['complete'] ?? false) !== true
            || ($payload['source_file'] ?? null) !== $file
            || ($payload['source_hash'] ?? null) !== hash('sha256', $source) || ! is_array($payload['concepts'] ?? null)) {
            throw new RuntimeException('The local hierarchy is incomplete or does not match the current vocabulary. Update this vocabulary.');
        }

        $nodes = [];
        foreach ($payload['concepts'] as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! filter_var($node['id'], FILTER_VALIDATE_URL)
                || ! is_string($node['label'] ?? null) || trim($node['label']) === ''
                || ! is_string($node['scheme'] ?? null) || $node['scheme'] !== $scheme
                || ! is_string($node['scheme_uri'] ?? null) || ! filter_var($node['scheme_uri'], FILTER_VALIDATE_URL)
                || ! is_string($node['language'] ?? null) || ! is_string($node['description'] ?? null)
                || ! is_bool($node['selectable'] ?? null) || ! is_array($node['parents'] ?? null)
                || ! array_is_list($node['parents']) || ! array_key_exists('classification_code', $node)
                || ($node['classification_code'] !== null && ! is_string($node['classification_code']))) {
                throw new RuntimeException('The local hierarchy contains an invalid concept.');
            }
            foreach ($node['parents'] as $parent) {
                if (! is_string($parent) || ! filter_var($parent, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('The local hierarchy contains an invalid relationship.');
                }
            }
            /** @var Concept $node */
            $node['id'] = $this->canonicalId($scheme, $node['id']);
            $node['parents'] = array_values(array_unique(array_map(fn (string $parent): string => $this->canonicalId($scheme, $parent), $node['parents'])));
            sort($node['parents'], SORT_STRING);
            if (isset($nodes[$node['id']])) {
                throw new RuntimeException('The local hierarchy contains duplicate concept identities.');
            }
            $nodes[$node['id']] = $node;
        }

        return $this->graphs[$scheme] = new SubjectHierarchyGraph($scheme, $nodes);
    }

    public function scheme(Subject $subject): ?string
    {
        if (PortalSubjectNormalizer::currentMslNodeUriForLegacyUri($subject->subject_scheme, $subject->value_uri) !== null) {
            return 'EPOS MSL vocabulary';
        }

        return $this->lookup->normalizeSupportedScheme($subject->subject_scheme);
    }

    public function resolve(Subject $subject, SubjectHierarchyGraph $graph): ?string
    {
        $key = $graph->scheme.'|'.hash('sha256', json_encode([$subject->id, $subject->value_uri, $subject->value, $subject->classification_code, $subject->breadcrumb_path], JSON_THROW_ON_ERROR));
        if (array_key_exists($key, $this->resolutions)) {
            return $this->resolutions[$key];
        }

        return $this->resolutions[$key] = $this->resolveUncached($subject, $graph);
    }

    private function resolveUncached(Subject $subject, SubjectHierarchyGraph $graph): ?string
    {
        $uri = PortalSubjectNormalizer::currentMslNodeUriForLegacyUri($subject->subject_scheme, $subject->value_uri) ?? trim((string) $subject->value_uri);
        $label = $this->label($subject->value);
        $labelCandidates = $graph->labelCandidates($label);
        $notationCandidates = $graph->notationCandidates($subject->classification_code);
        $candidates = array_fill_keys($graph->candidates($label, $subject->classification_code), true);
        if ($labelCandidates !== [] && $notationCandidates !== [] && $candidates === []) {
            return null;
        }
        if ($uri !== '') {
            $id = $this->canonicalId($graph->scheme, $uri);
            if ($graph->concept($id) === null || ($candidates !== [] && ! isset($candidates[$id]))) {
                return null;
            }

            return $id;
        }
        if (count($candidates) === 1) {
            return array_key_first($candidates);
        }

        // Full paths can resolve labels that occur more than once.
        $path = $subject->breadcrumb_path ?? $subject->value;
        $matches = [];
        foreach (array_keys($candidates) as $id) {
            if ($this->normalizedPath($graph->conceptPath($id)) === $this->normalizedPath($path)) {
                $matches[] = $id;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param iterable<Subject> $subjects
     * @return CorrectionCase|null
     */
    public function correction(iterable $subjects, SubjectHierarchyGraph $graph, string $broaderId): ?array
    {
        $broader = $graph->concept($broaderId);
        if ($broader === null || ! $broader['selectable']) {
            return null;
        }
        $subtree = $graph->subtree($broaderId);
        if ($subtree['leaf_ids'] === []) {
            return null;
        }
        $subtreeIds = array_fill_keys(array_column($subtree['nodes'], 'id'), true);
        $existing = [];
        $broaderSubjects = [];
        $relevant = [];
        foreach ($subjects as $subject) {
            if ($this->scheme($subject) !== $graph->scheme) {
                continue;
            }
            $id = $this->resolve($subject, $graph);
            if ($id === null) {
                // Unresolved controlled subjects could already represent a candidate.
                return null;
            }
            if (! isset($subtreeIds[$id])) {
                continue;
            }
            $relevant[$id] = true;
            if ($id === $broaderId) {
                $broaderSubjects[] = $subject->id;
            }
            if (in_array($id, $subtree['leaf_ids'], true)) {
                $existing[$id] = true;
            }
        }
        if ($broaderSubjects === [] || count($existing) === count($subtree['leaf_ids'])) {
            return null;
        }
        $existingIds = array_keys($existing);
        $relevantIds = array_keys($relevant);
        sort($existingIds, SORT_STRING);
        sort($relevantIds, SORT_STRING);
        sort($broaderSubjects, SORT_NUMERIC);
        $fingerprint = hash('sha256', json_encode([$graph->scheme, $broaderId, $subtree, $relevantIds], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'scheme' => $graph->scheme,
            'broader_id' => $broaderId,
            'broader_label' => $broader['label'],
            'fingerprint' => $fingerprint,
            ...$subtree,
            'existing_leaf_ids' => $existingIds,
            'broader_subject_ids' => $broaderSubjects,
            'source_file' => $this->lookup->localCacheFile($graph->scheme) ?? '',
        ];
    }

    public function canonicalSubjectScheme(string $scheme): string
    {
        return $this->lookup->canonicalSubjectScheme($scheme) ?? $scheme;
    }

    private function canonicalId(string $scheme, string $id): string
    {
        if (in_array($scheme, ['Science Keywords', 'Platforms', 'Instruments'], true)) {
            $uuid = GcmdUriHelper::extractUuid($id);
            if ($uuid !== null) {
                return GcmdUriHelper::buildConceptUri($uuid);
            }
        }

        return trim($id);
    }

    private function label(string $value): string
    {
        $segments = explode('>', html_entity_decode($value));

        return mb_strtolower(trim((string) array_last($segments)));
    }

    private function normalizedPath(string $value): string
    {
        return mb_strtolower(preg_replace('/\s*>\s*/u', ' > ', trim(html_entity_decode($value))) ?? $value);
    }
}
