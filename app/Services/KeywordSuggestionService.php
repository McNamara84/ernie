<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Subject;
use App\Models\ThesaurusSetting;
use App\Support\GemetVocabularyParser;
use App\Support\PortalSubjectNormalizer;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Service for providing portal keyword filter data from published resources.
 *
 * Returns deduplicated free keyword suggestions for autocomplete and
 * pruned thesaurus facets for controlled-vocabulary filtering in the
 * public portal.
 */
class KeywordSuggestionService
{
    use ChecksCacheTagging;

    private const THESAURUS_NOTATION_DELIMITER = '::';

    /**
     * @var list<array{file: string, fallback_scheme: string, setting_type: string|null}>
     */
    private const THESAURUS_SOURCES = [
        ['file' => 'gcmd-science-keywords.json', 'fallback_scheme' => 'Science Keywords', 'setting_type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS],
        ['file' => 'gcmd-platforms.json', 'fallback_scheme' => 'Platforms', 'setting_type' => ThesaurusSetting::TYPE_PLATFORMS],
        ['file' => 'gcmd-instruments.json', 'fallback_scheme' => 'Instruments', 'setting_type' => ThesaurusSetting::TYPE_INSTRUMENTS],
        ['file' => 'msl-vocabulary.json', 'fallback_scheme' => 'EPOS MSL vocabulary', 'setting_type' => null],
        ['file' => 'chronostrat-timescale.json', 'fallback_scheme' => PortalSubjectNormalizer::SCHEME_ICS_CHRONOSTRAT, 'setting_type' => ThesaurusSetting::TYPE_CHRONOSTRAT],
        ['file' => 'gemet-thesaurus.json', 'fallback_scheme' => GemetVocabularyParser::SCHEME_TITLE, 'setting_type' => ThesaurusSetting::TYPE_GEMET],
        ['file' => 'analytical-methods.json', 'fallback_scheme' => PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS, 'setting_type' => ThesaurusSetting::TYPE_ANALYTICAL_METHODS],
        ['file' => 'euroscivoc.json', 'fallback_scheme' => 'European Science Vocabulary (EuroSciVoc)', 'setting_type' => ThesaurusSetting::TYPE_EUROSCIVOC],
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
        /** @var array<int, array{value: string, scheme: null, count: int}> */
        return $this->getCacheInstance(CacheKey::PORTAL_FREE_KEYWORD_SUGGESTIONS->tags())
            ->remember(
                CacheKey::PORTAL_FREE_KEYWORD_SUGGESTIONS->key(),
                CacheKey::PORTAL_FREE_KEYWORD_SUGGESTIONS->ttl(),
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
        return $this->getCacheInstance(CacheKey::PORTAL_THESAURUS_FACETS->tags())
            ->remember(
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
    * @return array<int, array{id: string, scheme: string, subject_schemes: array<int, string>, descendant_ids: array<int, string>, descendant_values: array<int, string>}>
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
        $notationIndex = [];

        foreach ($this->getThesaurusFacets() as $facet) {
            foreach ($facet['roots'] as $root) {
                $this->indexFacetNode($root, $index, $notationIndex);
            }
        }

        $usedSubjects = $this->getUsedControlledSubjectIndex();
        $resolved = [];

        foreach ($normalizedIds as $nodeId) {
            $resolvedNode = $index[$nodeId] ?? $this->resolveSchemeScopedNotationSelection($nodeId, $notationIndex);
            if ($resolvedNode === null) {
                continue;
            }

            $subjectSchemes = array_keys($usedSubjects[$resolvedNode['scheme']]['schemes'] ?? []);

            if ($subjectSchemes === []) {
                $subjectSchemes = [$resolvedNode['scheme']];
            }

            $resolvedNode['subject_schemes'] = $subjectSchemes;
            $resolved[] = $resolvedNode;
        }

        return $resolved;
    }

    /**
     * Invalidate all cached portal keyword and thesaurus data.
     *
     * Clears the current free-keyword suggestions cache, the legacy mixed
     * keyword suggestions cache key, the pruned thesaurus facets, and the
     * controlled-subject index used to resolve selected thesaurus nodes.
     */
    public function invalidateCache(): void
    {
        CacheKey::PORTAL_FREE_KEYWORD_SUGGESTIONS->forget();
        CacheKey::PORTAL_KEYWORD_SUGGESTIONS->forget();
        CacheKey::PORTAL_THESAURUS_FACETS->forget();
        CacheKey::PORTAL_THESAURUS_SUBJECT_INDEX->forget();
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
            ->freeText()
            ->select('value')
            ->selectRaw('COUNT(DISTINCT resource_id) as usage_count')
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
        $usedSubjects = $this->getUsedControlledSubjectIndex();
        $facets = [];

        foreach ($this->getEnabledThesaurusSources() as $source) {
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
     * @return list<array{file: string, fallback_scheme: string, setting_type: string|null}>
     */
    private function getEnabledThesaurusSources(): array
    {
        /** @var array<string, bool> $activeSettings */
        $activeSettings = ThesaurusSetting::query()
            ->pluck('is_active', 'type')
            ->map(static fn (mixed $isActive): bool => (bool) $isActive)
            ->all();

        return array_values(array_filter(
            self::THESAURUS_SOURCES,
            static function (array $source) use ($activeSettings): bool {
                $settingType = $source['setting_type'];

                return $settingType === null
                    || ! array_key_exists($settingType, $activeSettings)
                    || $activeSettings[$settingType];
            },
        ));
    }

    /**
     * @return array<string, array{ids: array<string, true>, values: array<string, true>, schemes: array<string, true>}>
     */
    private function getUsedControlledSubjectIndex(): array
    {
        /** @var array<string, array{ids: array<string, true>, values: array<string, true>, schemes: array<string, true>}> */
        return $this->getCacheInstance(CacheKey::PORTAL_THESAURUS_SUBJECT_INDEX->tags())
            ->remember(
                CacheKey::PORTAL_THESAURUS_SUBJECT_INDEX->key(),
                CacheKey::PORTAL_THESAURUS_SUBJECT_INDEX->ttl(),
                fn (): array => $this->buildUsedControlledSubjectIndex(),
            );
    }

    /**
     * @return array<string, array{ids: array<string, true>, values: array<string, true>, schemes: array<string, true>}>
     */
    private function buildUsedControlledSubjectIndex(): array
    {
        $usedSubjects = [];

        Subject::query()
            ->controlled()
            ->select('subject_scheme', 'value', 'value_uri')
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

                $usedSubjects[$scheme] ??= ['ids' => [], 'values' => [], 'schemes' => []];

                $rawScheme = trim((string) $subject->subject_scheme);
                if ($rawScheme !== '') {
                    $usedSubjects[$scheme]['schemes'][$rawScheme] = true;
                }

                $valueUri = trim((string) $subject->value_uri);
                if ($valueUri !== '') {
                    $usedSubjects[$scheme]['ids'][$valueUri] = true;

                    return;
                }

                $normalizedValue = PortalSubjectNormalizer::normalizeControlledSubjectValue($subject->value);
                if ($normalizedValue !== null) {
                    $usedSubjects[$scheme]['values'][mb_strtolower($normalizedValue)] = true;
                }
            });

        return $usedSubjects;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadVocabularyRoots(string $fileName, string $fallbackScheme): array
    {
        if (! Storage::disk('local')->exists($fileName)) {
            return [];
        }

        $contents = Storage::disk('local')->get($fileName);
        if (! is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true);
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
        $rawChildren = $node['children'] ?? [];

        if (! is_array($rawChildren)) {
            $rawChildren = [];
        }

        foreach ($rawChildren as $child) {
            if (! is_array($child)) {
                continue;
            }

            $children[] = $this->normalizeVocabularyNode($child, $fallbackScheme);
        }

        $normalizedNode = [
            'id' => (string) ($node['id'] ?? ''),
            'text' => trim((string) ($node['text'] ?? '')),
            'language' => (string) ($node['language'] ?? 'en'),
            'scheme' => $this->normalizeScheme((string) ($node['scheme'] ?? $fallbackScheme)) ?? $fallbackScheme,
            'schemeURI' => (string) ($node['schemeURI'] ?? ''),
            'description' => (string) ($node['description'] ?? ''),
            'children' => $children,
        ];

        if (isset($node['notation'])) {
            $normalizedNode['notation'] = (string) $node['notation'];
        }

        return $normalizedNode;
    }

    /**
      * @param  array<string, mixed>|null  $lookup
      * @param  array<string, mixed>  $node
      * @param  array<int, string>  $pathSegments
     * @return array<string, mixed>|null
     */
    private function pruneVocabularyNode(array $node, ?array $lookup, array $pathSegments = []): ?array
    {
        $currentPathSegments = $this->extendPathSegments($pathSegments, $node);
        $children = [];

        foreach ($this->childrenOfNode($node) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $prunedChild = $this->pruneVocabularyNode($child, $lookup, $currentPathSegments);
            if ($prunedChild !== null) {
                $children[] = $prunedChild;
            }
        }

        if (! $this->isVocabularyNodeUsed($node, $lookup, $currentPathSegments) && $children === []) {
            return null;
        }

        $node['children'] = $children;

        return $node;
    }

    /**
      * @param  array<string, mixed>|null  $lookup
      * @param  array<string, mixed>  $node
      * @param  array<int, string>  $pathSegments
     */
    private function isVocabularyNodeUsed(array $node, ?array $lookup, array $pathSegments): bool
    {
        if ($lookup === null) {
            return false;
        }

        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId !== '' && isset($lookup['ids'][$nodeId])) {
            return true;
        }

        foreach ($this->buildBreadcrumbMatchValues($pathSegments) as $matchValue) {
            if (isset($lookup['values'][mb_strtolower($matchValue)])) {
                return true;
            }
        }

        return false;
    }

        /**
         * @param  array<string, array{id: string, scheme: string, descendant_ids: array<int, string>, descendant_values: array<int, string>}>  $index
         * @param  array<string, array<string, array{id: string, scheme: string, descendant_ids: array<int, string>, descendant_values: array<int, string>}>>  $notationIndex
         * @param  array<string, mixed>  $node
         * @param  array<int, string>  $pathSegments
         */
        private function indexFacetNode(array $node, array &$index, array &$notationIndex, array $pathSegments = []): void
    {
        $currentPathSegments = $this->extendPathSegments($pathSegments, $node);
        $descendantIds = [];
        $descendantValues = [];
        $this->collectDescendants($node, $descendantIds, $descendantValues, $pathSegments);

        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId !== '') {
            $resolvedNode = [
                'id' => $nodeId,
                'scheme' => (string) $node['scheme'],
                'descendant_ids' => array_values(array_unique($descendantIds)),
                'descendant_values' => array_values(array_unique($descendantValues)),
            ];

            $index[$nodeId] = $resolvedNode;

            $notation = trim((string) ($node['notation'] ?? ''));
            $normalizedScheme = $this->normalizeScheme((string) ($node['scheme'] ?? null));
            if ($notation !== '' && $normalizedScheme !== null) {
                $notationIndex[$normalizedScheme][$notation] = $resolvedNode;
            }
        }

        foreach ($this->childrenOfNode($node) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->indexFacetNode($child, $index, $notationIndex, $currentPathSegments);
        }
    }

    /**
     * @param  array<string, array<string, array{id: string, scheme: string, descendant_ids: array<int, string>, descendant_values: array<int, string>}>>  $notationIndex
     * @return array{id: string, scheme: string, descendant_ids: array<int, string>, descendant_values: array<int, string>}|null
     */
    private function resolveSchemeScopedNotationSelection(string $selectedNodeId, array $notationIndex): ?array
    {
        if (! str_contains($selectedNodeId, self::THESAURUS_NOTATION_DELIMITER)) {
            return null;
        }

        [$scheme, $notation] = explode(self::THESAURUS_NOTATION_DELIMITER, $selectedNodeId, 2);

        $normalizedScheme = $this->normalizeScheme($scheme);
        $normalizedNotation = trim($notation);

        if ($normalizedScheme === null || $normalizedNotation === '') {
            return null;
        }

        return $notationIndex[$normalizedScheme][$normalizedNotation] ?? null;
    }

        /**
         * @param  array<int, string>  $descendantIds
         * @param  array<int, string>  $descendantValues
         * @param  array<string, mixed>  $node
         * @param  array<int, string>  $pathSegments
         */
    private function collectDescendants(array $node, array &$descendantIds, array &$descendantValues, array $pathSegments = []): void
    {
        $currentPathSegments = $this->extendPathSegments($pathSegments, $node);
        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId !== '') {
            $descendantIds[] = $nodeId;
        }

        foreach ($this->buildBreadcrumbMatchValues($currentPathSegments) as $matchValue) {
            $descendantValues[] = $matchValue;
        }

        foreach ($this->childrenOfNode($node) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->collectDescendants($child, $descendantIds, $descendantValues, $currentPathSegments);
        }
    }

    /**
     * @param  array<int, string>  $pathSegments
     * @param  array<string, mixed>  $node
     * @return array<int, string>
     */
    private function extendPathSegments(array $pathSegments, array $node): array
    {
        $nodeText = trim((string) ($node['text'] ?? ''));
        if ($nodeText === '') {
            return $pathSegments;
        }

        $pathSegments[] = $nodeText;

        return $pathSegments;
    }

    /**
     * @param  array<int, string>  $pathSegments
     * @return array<int, string>
     */
    private function buildBreadcrumbMatchValues(array $pathSegments): array
    {
        if ($pathSegments === []) {
            return [];
        }

        $variants = [];
        $segmentCount = count($pathSegments);

        for ($offset = 0; $offset < $segmentCount; $offset++) {
            $variant = $this->normalizeControlledSubjectValue(
                implode(PortalSubjectNormalizer::BREADCRUMB_SEPARATOR, array_slice($pathSegments, $offset)),
            );

            if ($variant !== null) {
                $variants[] = $variant;
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<int, mixed>
     */
    private function childrenOfNode(array $node): array
    {
        $children = $node['children'] ?? [];

        return is_array($children) ? $children : [];
    }

    private function normalizeScheme(?string $scheme): ?string
    {
        return PortalSubjectNormalizer::normalizeScheme($scheme);
    }

    private function normalizeControlledSubjectValue(?string $value): ?string
    {
        return PortalSubjectNormalizer::normalizeControlledSubjectValue($value);
    }
}
