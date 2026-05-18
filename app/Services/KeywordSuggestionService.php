<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Subject;
use App\Support\AnalyticalMethodsVocabularyParser;
use App\Support\ChronostratVocabularyParser;
use App\Support\GemetVocabularyParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Service for providing keyword suggestions from published resources.
 *
 * Returns deduplicated keywords (Free Keywords + Thesaurus Keywords)
 * that are actually used by published resources, suitable for
 * autocomplete/filter UI in the public portal.
 */
class KeywordSuggestionService
{
    /**
     * @var list<array{file: string, fallback_scheme: string}>
     */
    private const THESAURUS_SOURCES = [
        ['file' => 'gcmd-science-keywords.json', 'fallback_scheme' => 'Science Keywords'],
        ['file' => 'gcmd-platforms.json', 'fallback_scheme' => 'Platforms'],
        ['file' => 'gcmd-instruments.json', 'fallback_scheme' => 'Instruments'],
        ['file' => 'msl-vocabulary.json', 'fallback_scheme' => 'EPOS MSL vocabulary'],
        ['file' => 'chronostrat-timescale.json', 'fallback_scheme' => 'International Chronostratigraphic Chart'],
        ['file' => 'gemet-thesaurus.json', 'fallback_scheme' => GemetVocabularyParser::SCHEME_TITLE],
        ['file' => 'analytical-methods.json', 'fallback_scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry'],
        ['file' => 'euroscivoc.json', 'fallback_scheme' => 'European Science Vocabulary (EuroSciVoc)'],
    ];

    /**
     * Get distinct free keyword suggestions from published resources.
     *
     * Results are cached for performance. Each suggestion includes
     * the keyword value and usage count.
     *
     * @return array<int, array{value: string, scheme: string|null, count: int}>
     */
    public function getSuggestions(): array
    {
        return $this->getFreeKeywordSuggestions();
    }

    /**
     * Get distinct free keyword suggestions from published resources.
     *
     * @return array<int, array{value: string, scheme: null, count: int}>
     */
    public function getFreeKeywordSuggestions(): array
    {
        /** @var array<int, array{value: string, scheme: string|null, count: int}> */
        return Cache::remember(
            CacheKey::PORTAL_KEYWORD_SUGGESTIONS->key(),
            CacheKey::PORTAL_KEYWORD_SUGGESTIONS->ttl(),
            fn (): array => $this->fetchFreeKeywordSuggestions(),
        );
    }

    /**
     * Get pruned thesaurus trees containing only terms used by published resources.
     *
     * @return array<int, array{scheme: string, roots: array<int, array<string, mixed>>}>
     */
    public function getThesaurusFacets(): array
    {
        /** @var array<int, array{scheme: string, roots: array<int, array<string, mixed>>}> */
        return Cache::remember(
            CacheKey::PORTAL_THESAURUS_FACETS->key(),
            CacheKey::PORTAL_THESAURUS_FACETS->ttl(),
            fn (): array => $this->fetchThesaurusFacets(),
        );
    }

    /**
     * Resolve selected thesaurus node IDs to the descendant IDs/text values
     * used for query building.
     *
     * @param  array<int, string>  $selectedNodeIds
     * @return array<int, array{id: string, scheme: string, descendant_ids: array<int, string>, descendant_values: array<int, string>}>
     */
    public function resolveSelectedThesaurusNodes(array $selectedNodeIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (string $value): string => trim($value), $selectedNodeIds),
            static fn (string $value): bool => $value !== '',
        )));

        if ($normalizedIds === []) {
            return [];
        }

        $index = [];

        foreach ($this->getThesaurusFacets() as $facet) {
            foreach ($facet['roots'] as $root) {
                $this->indexFacetNode($root, $index);
            }
        }

        $resolved = [];

        foreach ($normalizedIds as $nodeId) {
            if (! isset($index[$nodeId])) {
                continue;
            }

            $resolved[] = $index[$nodeId];
        }

        return $resolved;
    }

    /**
     * Invalidate the cached keyword suggestions.
     */
    public function invalidateCache(): void
    {
        Cache::forget(CacheKey::PORTAL_KEYWORD_SUGGESTIONS->key());
        Cache::forget(CacheKey::PORTAL_THESAURUS_FACETS->key());
    }

    /**
     * Fetch distinct free keywords from the database.
     *
     * Only includes keywords from resources that have a published landing page.
     * Groups by value, counting occurrences.
     *
     * @return array<int, array{value: string, scheme: null, count: int}>
     */
    private function fetchFreeKeywordSuggestions(): array
    {
        return Subject::query()
            ->select('value')
            ->selectRaw('COUNT(DISTINCT resource_id) as usage_count')
            ->whereNull('subject_scheme')
            ->whereHas('resource', function ($query): void {
                $query->whereHas('landingPage', function ($q): void {
                    $q->where('is_published', true);
                });
            })
            ->groupBy('value')
            ->orderBy('value')
            ->get()
            ->map(static fn (Subject $subject): array => [
                'value' => $subject->value,
                'scheme' => null,
                'count' => (int) $subject->getAttribute('usage_count'),
            ])
            ->all();
    }

    /**
     * Build thesaurus facets from published controlled subjects and vocabulary files.
     *
     * @return array<int, array{scheme: string, roots: array<int, array<string, mixed>>}>
     */
    private function fetchThesaurusFacets(): array
    {
        $usedSubjects = $this->buildUsedControlledSubjectIndex();
        $facets = [];

        foreach (self::THESAURUS_SOURCES as $source) {
            $roots = $this->loadVocabularyRoots($source['file'], $source['fallback_scheme']);
            if ($roots === []) {
                continue;
            }

            $scheme = $roots[0]['scheme'] ?? $source['fallback_scheme'];
            $lookup = $usedSubjects[$scheme] ?? null;
            $prunedRoots = [];

            foreach ($roots as $root) {
                $pruned = $this->pruneVocabularyNode($root, $lookup);
                if ($pruned !== null) {
                    $prunedRoots[] = $pruned;
                }
            }

            if ($prunedRoots === []) {
                continue;
            }

            $facets[] = [
                'scheme' => $scheme,
                'roots' => $prunedRoots,
            ];
        }

        return $facets;
    }

    /**
     * @return array<string, array{ids: array<string, true>, values: array<string, true>}>
     */
    private function buildUsedControlledSubjectIndex(): array
    {
        $usedSubjects = [];

        Subject::query()
            ->select('subject_scheme', 'value', 'value_uri')
            ->whereNotNull('subject_scheme')
            ->whereHas('resource', function ($query): void {
                $query->whereHas('landingPage', function ($q): void {
                    $q->where('is_published', true);
                });
            })
            ->get()
            ->each(function (Subject $subject) use (&$usedSubjects): void {
                $scheme = $this->normalizeScheme($subject->subject_scheme);
                if ($scheme === null) {
                    return;
                }

                $usedSubjects[$scheme] ??= ['ids' => [], 'values' => []];

                $trimmedValue = trim($subject->value);
                if ($trimmedValue !== '') {
                    $usedSubjects[$scheme]['values'][mb_strtolower($trimmedValue)] = true;
                }

                $valueUri = trim((string) $subject->value_uri);
                if ($valueUri !== '') {
                    $usedSubjects[$scheme]['ids'][$valueUri] = true;
                }
            });

        return $usedSubjects;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadVocabularyRoots(string $fileName, string $fallbackScheme): array
    {
        $path = 'private/' . $fileName;
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $rawRoots = array_is_list($decoded) ? $decoded : ($decoded['data'] ?? []);
        if (! is_array($rawRoots)) {
            return [];
        }

        $roots = [];
        foreach ($rawRoots as $root) {
            if (! is_array($root)) {
                continue;
            }

            $roots[] = $this->normalizeVocabularyNode($root, $fallbackScheme);
        }

        return $roots;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalizeVocabularyNode(array $node, string $fallbackScheme): array
    {
        $children = [];

        foreach (($node['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $children[] = $this->normalizeVocabularyNode($child, $fallbackScheme);
        }

        return [
            'id' => (string) ($node['id'] ?? ''),
            'text' => trim((string) ($node['text'] ?? '')),
            'language' => (string) ($node['language'] ?? 'en'),
            'scheme' => $this->normalizeScheme((string) ($node['scheme'] ?? $fallbackScheme)) ?? $fallbackScheme,
            'schemeURI' => (string) ($node['schemeURI'] ?? ''),
            'description' => (string) ($node['description'] ?? ''),
            'notation' => isset($node['notation']) ? (string) $node['notation'] : null,
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $lookup
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function pruneVocabularyNode(array $node, ?array $lookup): ?array
    {
        $children = [];

        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $prunedChild = $this->pruneVocabularyNode($child, $lookup);
            if ($prunedChild !== null) {
                $children[] = $prunedChild;
            }
        }

        if (! $this->isVocabularyNodeUsed($node, $lookup) && $children === []) {
            return null;
        }

        $node['children'] = $children;

        return $node;
    }

    /**
     * @param  array<string, mixed>|null  $lookup
     * @param  array<string, mixed>  $node
     */
    private function isVocabularyNodeUsed(array $node, ?array $lookup): bool
    {
        if ($lookup === null) {
            return false;
        }

        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId !== '' && isset($lookup['ids'][$nodeId])) {
            return true;
        }

        $nodeText = trim((string) ($node['text'] ?? ''));
        if ($nodeText === '') {
            return false;
        }

        return isset($lookup['values'][mb_strtolower($nodeText)]);
    }

    /**
     * @param  array<string, array{id: string, scheme: string, descendant_ids: array<int, string>, descendant_values: array<int, string>}>  $index
     * @param  array<string, mixed>  $node
     */
    private function indexFacetNode(array $node, array &$index): void
    {
        $descendantIds = [];
        $descendantValues = [];
        $this->collectDescendants($node, $descendantIds, $descendantValues);

        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId !== '') {
            $index[$nodeId] = [
                'id' => $nodeId,
                'scheme' => (string) $node['scheme'],
                'descendant_ids' => array_values(array_unique($descendantIds)),
                'descendant_values' => array_values(array_unique($descendantValues)),
            ];
        }

        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->indexFacetNode($child, $index);
        }
    }

    /**
     * @param  array<int, string>  $descendantIds
     * @param  array<int, string>  $descendantValues
     * @param  array<string, mixed>  $node
     */
    private function collectDescendants(array $node, array &$descendantIds, array &$descendantValues): void
    {
        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId !== '') {
            $descendantIds[] = $nodeId;
        }

        $nodeText = trim((string) ($node['text'] ?? ''));
        if ($nodeText !== '') {
            $descendantValues[] = $nodeText;
        }

        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->collectDescendants($child, $descendantIds, $descendantValues);
        }
    }

    private function normalizeScheme(?string $scheme): ?string
    {
        $trimmed = trim((string) $scheme);
        if ($trimmed === '') {
            return null;
        }

        $normalized = mb_strtolower($trimmed);

        return match (true) {
            str_contains($normalized, 'science keywords') => 'Science Keywords',
            str_contains($normalized, 'platform') => 'Platforms',
            str_contains($normalized, 'instrument') => 'Instruments',
            str_contains($normalized, 'epos msl'),
            str_contains($normalized, 'msl vocabulary') => 'EPOS MSL vocabulary',
            str_contains($normalized, 'chronostrat') => ChronostratVocabularyParser::SCHEME_TITLE,
            str_contains($normalized, 'gemet') => GemetVocabularyParser::SCHEME_TITLE,
            str_contains($normalized, 'analytical') && str_contains($normalized, 'method') => AnalyticalMethodsVocabularyParser::SCHEME_TITLE,
            str_contains($normalized, 'euroscivoc'),
            str_contains($normalized, 'european science vocabulary') => 'European Science Vocabulary (EuroSciVoc)',
            default => $trimmed,
        };
    }
}
