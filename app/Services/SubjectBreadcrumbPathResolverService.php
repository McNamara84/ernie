<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GemetVocabularyParser;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;
use Illuminate\Support\Facades\Storage;

final class SubjectBreadcrumbPathResolverService
{
    /** @var array<string, array{file: string, fallback_scheme: string, fallback_scheme_uri?: string}> */
    private const SOURCES = [
        'Science Keywords' => [
            'file' => 'gcmd-science-keywords.json',
            'fallback_scheme' => 'Science Keywords',
            'fallback_scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        ],
        'Platforms' => [
            'file' => 'gcmd-platforms.json',
            'fallback_scheme' => 'Platforms',
            'fallback_scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
        ],
        'Instruments' => [
            'file' => 'gcmd-instruments.json',
            'fallback_scheme' => 'Instruments',
            'fallback_scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
        ],
        'EPOS MSL vocabulary' => [
            'file' => 'msl-vocabulary.json',
            'fallback_scheme' => 'EPOS MSL vocabulary',
            'fallback_scheme_uri' => 'https://epos-msl.uu.nl/voc',
        ],
        GemetVocabularyParser::SCHEME_TITLE => [
            'file' => 'gemet-thesaurus.json',
            'fallback_scheme' => GemetVocabularyParser::SCHEME_TITLE,
            'fallback_scheme_uri' => 'http://www.eionet.europa.eu/gemet/concept/',
        ],
        PortalSubjectNormalizer::SCHEME_ICS_CHRONOSTRAT => [
            'file' => 'chronostrat-timescale.json',
            'fallback_scheme' => PortalSubjectNormalizer::SCHEME_ICS_CHRONOSTRAT,
            'fallback_scheme_uri' => 'http://resource.geosciml.org/vocabulary/timescale/gts2020',
        ],
        PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS => [
            'file' => 'analytical-methods.json',
            'fallback_scheme' => PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS,
            'fallback_scheme_uri' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
        ],
        'European Science Vocabulary (EuroSciVoc)' => ['file' => 'euroscivoc.json', 'fallback_scheme' => 'European Science Vocabulary (EuroSciVoc)'],
    ];

    /** @var array<string, array<string, string>> */
    private array $pathsById = [];

    /** @var array<string, array<string, string>> */
    private array $pathsByNotation = [];

    /** @var array<string, array<string, string>> */
    private array $pathsByLeaf = [];

    /** @var array<string, array<string, array{id: string, text: string, path: string, schemeURI: string|null}>> */
    private array $nodesByPath = [];

    /** @var array<string, array<string, true>> */
    private array $ambiguousLeafKeys = [];

    /** @var array<string, array<string, true>> */
    private array $ambiguousPathKeys = [];

    /** @var array<string, true> */
    private array $indexedSchemes = [];

    public function resolve(?string $subjectScheme, ?string $valueUri, ?string $classificationCode, ?string $subjectValue): ?string
    {
        $embeddedPath = SubjectBreadcrumbPath::preferredPath(null, $subjectValue);
        if ($embeddedPath !== null) {
            return $embeddedPath;
        }

        $normalizedScheme = PortalSubjectNormalizer::normalizeScheme($subjectScheme);
        if ($normalizedScheme === null) {
            return null;
        }

        $this->buildIndexesForScheme($normalizedScheme);

        $nodeId = trim((string) $valueUri);
        if ($nodeId !== '' && isset($this->pathsById[$normalizedScheme][$nodeId])) {
            return $this->pathsById[$normalizedScheme][$nodeId];
        }

        $notation = trim((string) $classificationCode);
        if ($notation !== '' && isset($this->pathsByNotation[$normalizedScheme][$notation])) {
            return $this->pathsByNotation[$normalizedScheme][$notation];
        }

        $leafKey = $this->normalizeLeafLookup($subjectValue);
        if ($leafKey !== null && isset($this->pathsByLeaf[$normalizedScheme][$leafKey])) {
            return $this->pathsByLeaf[$normalizedScheme][$leafKey];
        }

        return null;
    }

    /**
     * Resolve a controlled keyword from a legacy DataCite subject that only
     * carries a full breadcrumb path instead of a stable valueURI.
     *
     * @return array{id: string, text: string, path: string, scheme: string, schemeURI: string|null}|null
     */
    public function resolveKeywordFromPath(?string $subjectScheme, ?string $subjectValue): ?array
    {
        $normalizedScheme = PortalSubjectNormalizer::normalizeScheme($subjectScheme);
        if ($normalizedScheme === null) {
            return null;
        }

        $this->buildIndexesForScheme($normalizedScheme);

        $pathKey = $this->normalizePathLookup($subjectValue, $normalizedScheme);
        if ($pathKey === null || isset($this->ambiguousPathKeys[$normalizedScheme][$pathKey])) {
            return null;
        }

        $node = $this->nodesByPath[$normalizedScheme][$pathKey] ?? null;
        if ($node === null) {
            return null;
        }

        return [
            'id' => $node['id'],
            'text' => $node['text'] !== '' ? $node['text'] : (SubjectBreadcrumbPath::leaf($node['path']) ?? ''),
            'path' => $node['path'],
            'scheme' => $normalizedScheme,
            'schemeURI' => $node['schemeURI'] ?? $this->fallbackSchemeUri($normalizedScheme),
        ];
    }

    public function resolveSchemeUri(?string $subjectScheme): ?string
    {
        $normalizedScheme = PortalSubjectNormalizer::normalizeScheme($subjectScheme);

        return $normalizedScheme !== null ? $this->fallbackSchemeUri($normalizedScheme) : null;
    }

    private function buildIndexesForScheme(string $scheme): void
    {
        if (isset($this->indexedSchemes[$scheme])) {
            return;
        }

        $this->indexedSchemes[$scheme] = true;

        $source = self::SOURCES[$scheme] ?? null;
        if ($source === null) {
            return;
        }

        foreach ($this->loadVocabularyRoots($source['file'], $source['fallback_scheme']) as $root) {
            $this->indexNode($root, $scheme, []);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $pathSegments
     */
    private function indexNode(array $node, string $scheme, array $pathSegments): void
    {
        $currentPathSegments = $pathSegments;
        $nodeText = trim((string) ($node['text'] ?? ''));

        if ($nodeText !== '') {
            $currentPathSegments[] = $nodeText;
        }

        $currentPath = $this->buildPath($scheme, $currentPathSegments);
        if ($currentPath !== null) {
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId !== '') {
                $this->pathsById[$scheme][$nodeId] = $currentPath;
            }

            $notation = trim((string) ($node['notation'] ?? ''));
            if ($notation !== '') {
                $this->pathsByNotation[$scheme][$notation] = $currentPath;
            }

            $leafKey = $this->normalizeLeafLookup(SubjectBreadcrumbPath::leaf($currentPath));
            if ($leafKey !== null && ! isset($this->ambiguousLeafKeys[$scheme][$leafKey])) {
                if (! isset($this->pathsByLeaf[$scheme][$leafKey])) {
                    $this->pathsByLeaf[$scheme][$leafKey] = $currentPath;
                } elseif ($this->pathsByLeaf[$scheme][$leafKey] !== $currentPath) {
                    unset($this->pathsByLeaf[$scheme][$leafKey]);
                    $this->ambiguousLeafKeys[$scheme][$leafKey] = true;
                }
            }

            $pathKey = $this->normalizePathLookup($currentPath, $scheme);
            if ($pathKey !== null && $nodeId !== '' && ! isset($this->ambiguousPathKeys[$scheme][$pathKey])) {
                $schemeUri = $this->filledString($node['schemeURI'] ?? null) ?? $this->fallbackSchemeUri($scheme);
                $resolvedNode = [
                    'id' => $nodeId,
                    'text' => $nodeText,
                    'path' => $currentPath,
                    'schemeURI' => $schemeUri,
                ];

                if (! isset($this->nodesByPath[$scheme][$pathKey])) {
                    $this->nodesByPath[$scheme][$pathKey] = $resolvedNode;
                } elseif ($this->nodesByPath[$scheme][$pathKey] !== $resolvedNode) {
                    unset($this->nodesByPath[$scheme][$pathKey]);
                    $this->ambiguousPathKeys[$scheme][$pathKey] = true;
                }
            }
        }

        foreach ($this->childrenOfNode($node) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->indexNode($child, $scheme, $currentPathSegments);
        }
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

        return [
            'id' => trim((string) ($node['id'] ?? '')),
            'text' => trim((string) ($node['text'] ?? '')),
            'notation' => isset($node['notation']) ? trim((string) $node['notation']) : '',
            'schemeURI' => $this->filledString($node['schemeURI'] ?? $node['schemeUri'] ?? null) ?? '',
            'scheme' => PortalSubjectNormalizer::normalizeScheme((string) ($node['scheme'] ?? $fallbackScheme)) ?? $fallbackScheme,
            'children' => $children,
        ];
    }

    /**
     * @param  array<int, string>  $pathSegments
     */
    private function buildPath(string $scheme, array $pathSegments): ?string
    {
        $segments = array_values(array_filter(
            array_map('trim', $pathSegments),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            return null;
        }

        if ($this->shouldDropLeadingSegment($segments[0], $scheme)) {
            array_shift($segments);
        }

        if ($segments === []) {
            return null;
        }

        return SubjectBreadcrumbPath::normalize(
            implode(PortalSubjectNormalizer::BREADCRUMB_SEPARATOR, $segments),
        );
    }

    private function shouldDropLeadingSegment(string $segment, string $scheme): bool
    {
        $normalizedSegment = PortalSubjectNormalizer::normalizeScheme($segment);

        return $normalizedSegment !== null && $normalizedSegment === $scheme;
    }

    private function normalizeLeafLookup(?string $subjectValue): ?string
    {
        $leaf = SubjectBreadcrumbPath::leaf($subjectValue);
        if ($leaf === null) {
            return null;
        }

        $normalizedLeaf = trim($leaf);

        return $normalizedLeaf !== '' ? mb_strtolower($normalizedLeaf) : null;
    }

    private function normalizePathLookup(?string $path, ?string $scheme): ?string
    {
        $segments = SubjectBreadcrumbPath::segments($path);
        if ($segments === []) {
            return null;
        }

        if ($scheme !== null && $this->shouldDropLeadingSegment($segments[0], $scheme)) {
            array_shift($segments);
        }

        if ($segments === []) {
            return null;
        }

        $normalizedPath = SubjectBreadcrumbPath::normalize(
            implode(PortalSubjectNormalizer::BREADCRUMB_SEPARATOR, $segments),
        );

        return $normalizedPath !== null ? mb_strtolower($normalizedPath) : null;
    }

    private function fallbackSchemeUri(string $scheme): ?string
    {
        return self::SOURCES[$scheme]['fallback_scheme_uri'] ?? null;
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
}
