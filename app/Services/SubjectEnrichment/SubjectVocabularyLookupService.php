<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

use App\Support\GcmdUriHelper;
use App\Support\GemetVocabularyParser;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;
use Illuminate\Support\Facades\Storage;

/**
 * Builds local indexes over the first-release subject vocabularies.
 */
final class SubjectVocabularyLookupService
{
    /** @var array<string, array{file: string, scheme_uri?: string, scheme_uri_config?: string, source: string, source_registry_url?: string, source_registry_url_config?: string, generated_by: string, version?: string, version_config?: string}> */
    private const SOURCES = [
        'Science Keywords' => [
            'file' => 'gcmd-science-keywords.json',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'source' => 'nasa_gcmd_kms',
            'source_registry_url' => 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords?format=rdf',
            'generated_by' => 'get-gcmd-science-keywords',
        ],
        'Platforms' => [
            'file' => 'gcmd-platforms.json',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
            'source' => 'nasa_gcmd_kms',
            'source_registry_url' => 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms?format=rdf',
            'generated_by' => 'get-gcmd-platforms',
        ],
        'Instruments' => [
            'file' => 'gcmd-instruments.json',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
            'source' => 'nasa_gcmd_kms',
            'source_registry_url' => 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments?format=rdf',
            'generated_by' => 'get-gcmd-instruments',
        ],
        'EPOS MSL vocabulary' => [
            'file' => 'msl-vocabulary.json',
            'scheme_uri' => 'https://epos-msl.uu.nl/voc',
            'source' => 'utrecht_msl_vocabulary',
            'source_registry_url' => 'https://epos-msl.uu.nl/voc',
            'generated_by' => 'get-msl-keywords',
        ],
        GemetVocabularyParser::SCHEME_TITLE => [
            'file' => 'gemet-thesaurus.json',
            'scheme_uri' => 'http://www.eionet.europa.eu/gemet/concept/',
            'source' => 'eea_gemet_api',
            'source_registry_url' => 'https://www.eionet.europa.eu/gemet/',
            'generated_by' => 'get-gemet-thesaurus',
        ],
        PortalSubjectNormalizer::SCHEME_ICS_CHRONOSTRAT => [
            'file' => 'chronostrat-timescale.json',
            'scheme_uri' => 'http://resource.geosciml.org/vocabulary/timescale/gts2020',
            'source' => 'ardc_linked_data_api',
            'source_registry_url' => 'https://vocabs.ardc.edu.au/repository/api/lda/csiro/international-chronostratigraphic-chart/geologic-time-scale-2020/concept.json',
            'generated_by' => 'get-chronostrat-timescale',
        ],
        PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS => [
            'file' => 'analytical-methods.json',
            'scheme_uri' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
            'source' => 'ardc_linked_data_api',
            'source_registry_url' => 'https://vocabs.ardc.edu.au/repository/api/lda/earthchem-georoc/analytical-methods-for-geochemistry-and-cosmochemistry/current/concept.json',
            'generated_by' => 'get-analytical-methods',
            'version_config' => 'ardc.analytical_methods.default_version',
        ],
        'European Science Vocabulary (EuroSciVoc)' => [
            'file' => 'euroscivoc.json',
            'scheme_uri_config' => 'euroscivoc.concept_scheme_uri',
            'source' => 'eu_publications_office_euroscivoc',
            'source_registry_url_config' => 'euroscivoc.download_url',
            'generated_by' => 'get-euroscivoc',
        ],
    ];

    /** @var array<string, true> */
    private const GCMD_SCHEMES = [
        'Science Keywords' => true,
        'Platforms' => true,
        'Instruments' => true,
    ];

    /** @var array<string, true> */
    private array $indexedSchemes = [];

    /** @var array<string, bool> */
    private array $availableSchemes = [];

    /** @var array<string, SubjectVocabularySource> */
    private array $sourceByScheme = [];

    /** @var array<string, array<string, SubjectVocabularyConcept>> */
    private array $byId = [];

    /** @var array<string, array<string, SubjectVocabularyConcept>> */
    private array $byNotation = [];

    /** @var array<string, array<string, array<string, SubjectVocabularyConcept>>> */
    private array $byPath = [];

    /** @var array<string, array<string, array<string, SubjectVocabularyConcept>>> */
    private array $byLeaf = [];

    /** @var array<string, array<string, SubjectVocabularyConcept>> */
    private array $globalExactLabel = [];

    public function normalizeSupportedScheme(?string $scheme): ?string
    {
        $normalizedScheme = PortalSubjectNormalizer::normalizeScheme($scheme);

        return $normalizedScheme !== null && isset(self::SOURCES[$normalizedScheme])
            ? $normalizedScheme
            : null;
    }

    /**
     * @return list<string>
     */
    public function supportedSchemes(): array
    {
        return array_keys(self::SOURCES);
    }

    public function isSchemeAvailable(string $scheme): bool
    {
        $this->buildIndexesForScheme($scheme);

        return $this->availableSchemes[$scheme] ?? false;
    }

    public function sourceForScheme(string $scheme): ?SubjectVocabularySource
    {
        $this->buildIndexesForScheme($scheme);

        return $this->sourceByScheme[$scheme] ?? null;
    }

    public function canonicalSchemeUri(string $scheme): ?string
    {
        $source = self::SOURCES[$scheme] ?? null;
        if ($source === null) {
            return null;
        }

        return $this->configuredString($source['scheme_uri_config'] ?? null)
            ?? $source['scheme_uri']
            ?? null;
    }

    public function findById(string $scheme, ?string $id): SubjectVocabularyMatchSet
    {
        $this->buildIndexesForScheme($scheme);
        $id = $this->filledString($id);
        if ($id === null) {
            return SubjectVocabularyMatchSet::empty();
        }

        $candidates = [];
        foreach ($this->identifierLookupKeys($id, $scheme) as $lookupKey) {
            $concept = $this->byId[$scheme][$lookupKey] ?? null;
            if ($concept === null) {
                continue;
            }

            $candidates[$this->conceptKey($concept)] = $concept;
        }

        return new SubjectVocabularyMatchSet(array_values($candidates));
    }

    public function findGlobalById(?string $id): SubjectVocabularyMatchSet
    {
        $id = $this->filledString($id);
        if ($id === null) {
            return SubjectVocabularyMatchSet::empty();
        }

        $candidates = [];
        foreach ($this->supportedSchemes() as $scheme) {
            foreach ($this->findById($scheme, $id)->candidates as $concept) {
                $candidates[$this->conceptKey($concept)] = $concept;
            }
        }

        return new SubjectVocabularyMatchSet(array_values($candidates));
    }

    public function findByNotation(string $scheme, ?string $notation): SubjectVocabularyMatchSet
    {
        $this->buildIndexesForScheme($scheme);
        $notationKey = $this->lookupKey($notation);
        if ($notationKey === null) {
            return SubjectVocabularyMatchSet::empty();
        }

        $concept = $this->byNotation[$scheme][$notationKey] ?? null;

        return new SubjectVocabularyMatchSet($concept === null ? [] : [$concept]);
    }

    public function findExactPath(string $scheme, ?string $path): SubjectVocabularyMatchSet
    {
        $this->buildIndexesForScheme($scheme);
        $pathKey = $this->pathLookupKey($path, $scheme);
        if ($pathKey === null) {
            return SubjectVocabularyMatchSet::empty();
        }

        return new SubjectVocabularyMatchSet(array_values($this->byPath[$scheme][$pathKey] ?? []));
    }

    public function findUniqueLegacyPath(string $scheme, ?string $path): SubjectVocabularyMatchSet
    {
        $this->buildIndexesForScheme($scheme);
        $segments = $this->pathSegmentsForLookup($path, $scheme);
        if (count($segments) < 2) {
            return SubjectVocabularyMatchSet::empty('legacy_ordered_subsequence');
        }

        $leafKey = $segments[array_key_last($segments)];
        $candidates = $this->byLeaf[$scheme][$leafKey] ?? [];
        if ($candidates === []) {
            return SubjectVocabularyMatchSet::empty('legacy_ordered_subsequence');
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            if ($this->isOrderedSubsequence($segments, $this->pathSegmentsForLookup($candidate->path, $scheme))) {
                $matches[$this->conceptKey($candidate)] = $candidate;
            }
        }

        return new SubjectVocabularyMatchSet(array_values($matches), 'legacy_ordered_subsequence');
    }

    public function findUniqueLeafInScheme(string $scheme, ?string $value): SubjectVocabularyMatchSet
    {
        $this->buildIndexesForScheme($scheme);
        $leafKey = $this->leafLookupKey($value);
        if ($leafKey === null) {
            return SubjectVocabularyMatchSet::empty();
        }

        return new SubjectVocabularyMatchSet(array_values($this->byLeaf[$scheme][$leafKey] ?? []));
    }

    public function findGlobalExactLabel(?string $label): SubjectVocabularyMatchSet
    {
        $this->buildAllIndexes();
        $labelKey = $this->labelLookupKey($label);
        if ($labelKey === null) {
            return SubjectVocabularyMatchSet::empty();
        }

        return new SubjectVocabularyMatchSet(array_values($this->globalExactLabel[$labelKey] ?? []));
    }

    public function findGlobalExactPath(?string $path): SubjectVocabularyMatchSet
    {
        $this->buildAllIndexes();
        $candidates = [];

        foreach ($this->supportedSchemes() as $scheme) {
            foreach ($this->findExactPath($scheme, $path)->candidates as $concept) {
                $candidates[$this->conceptKey($concept)] = $concept;
            }
        }

        return new SubjectVocabularyMatchSet(array_values($candidates));
    }

    private function buildAllIndexes(): void
    {
        foreach ($this->supportedSchemes() as $scheme) {
            $this->buildIndexesForScheme($scheme);
        }
    }

    private function buildIndexesForScheme(string $scheme): void
    {
        if (isset($this->indexedSchemes[$scheme])) {
            return;
        }

        $this->indexedSchemes[$scheme] = true;
        $sourceDefinition = self::SOURCES[$scheme] ?? null;
        if ($sourceDefinition === null) {
            $this->availableSchemes[$scheme] = false;

            return;
        }

        $source = $this->buildSource($scheme, $sourceDefinition, null);
        $this->sourceByScheme[$scheme] = $source;

        $fileName = $sourceDefinition['file'];
        if (! Storage::disk('local')->exists($fileName)) {
            $this->availableSchemes[$scheme] = false;

            return;
        }

        $contents = Storage::disk('local')->get($fileName);
        if (! is_string($contents)) {
            $this->availableSchemes[$scheme] = false;

            return;
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            $this->availableSchemes[$scheme] = false;

            return;
        }

        $source = $this->buildSource($scheme, $sourceDefinition, $decoded);
        $this->sourceByScheme[$scheme] = $source;

        $roots = $this->rawRoots($decoded);
        if ($roots === []) {
            $this->availableSchemes[$scheme] = true;

            return;
        }

        $this->availableSchemes[$scheme] = true;

        foreach ($roots as $root) {
            if (! is_array($root)) {
                continue;
            }

            $this->indexNode($root, $scheme, [], $source);
        }
    }

    /**
     * @param  array<string, string>  $sourceDefinition
     * @param  array<string, mixed>|null  $decoded
     */
    private function buildSource(string $scheme, array $sourceDefinition, ?array $decoded): SubjectVocabularySource
    {
        $schemeUri = $this->configuredString($sourceDefinition['scheme_uri_config'] ?? null)
            ?? $sourceDefinition['scheme_uri']
            ?? '';
        $sourceRegistryUrl = $this->configuredString($sourceDefinition['source_registry_url_config'] ?? null)
            ?? $sourceDefinition['source_registry_url']
            ?? $schemeUri;
        $version = $this->configuredString($sourceDefinition['version_config'] ?? null)
            ?? $sourceDefinition['version']
            ?? $this->filledString($decoded['version'] ?? null);

        return new SubjectVocabularySource(
            scheme: $scheme,
            schemeUri: $schemeUri,
            source: $sourceDefinition['source'],
            sourceRegistryUrl: $sourceRegistryUrl,
            localCacheFile: $sourceDefinition['file'],
            localCacheUpdatedAt: $this->filledString($decoded['lastUpdated'] ?? $decoded['last_updated'] ?? null),
            version: $version,
            generatedBy: $sourceDefinition['generated_by'],
        );
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<mixed>
     */
    private function rawRoots(array $decoded): array
    {
        $rawRoots = array_is_list($decoded) ? $decoded : ($decoded['data'] ?? []);

        return is_array($rawRoots) ? array_values($rawRoots) : [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $parentSegments
     */
    private function indexNode(array $node, string $fallbackScheme, array $parentSegments, SubjectVocabularySource $source): void
    {
        $nodeText = $this->nodeText($node);
        $nodeScheme = $this->normalizeSupportedScheme($this->filledString($node['scheme'] ?? null)) ?? $fallbackScheme;
        $currentSegments = $parentSegments;

        if ($nodeText !== null) {
            $currentSegments[] = $nodeText;
        }

        $path = $this->buildPath($nodeScheme, $currentSegments);
        $nodeId = $this->canonicalConceptId($this->filledString(
            $node['id']
                ?? $node['uri']
                ?? $node['valueURI']
                ?? $node['valueUri']
                ?? $node['@id']
                ?? null
        ), $nodeScheme);

        if ($nodeId !== null && $nodeText !== null && $path !== null) {
            $concept = new SubjectVocabularyConcept(
                id: $nodeId,
                label: $nodeText,
                path: $path,
                scheme: $nodeScheme,
                schemeUri: $this->filledString($node['schemeURI'] ?? $node['schemeUri'] ?? null)
                    ?? $this->canonicalSchemeUri($nodeScheme)
                    ?? $source->schemeUri,
                classificationCode: $this->classificationCode($node),
                language: $this->filledString($node['language'] ?? $node['lang'] ?? $node['xml:lang'] ?? null) ?? 'en',
                source: $this->sourceByScheme[$nodeScheme] ?? $source,
                synonyms: $this->synonyms($node),
            );

            $this->indexConcept($concept);
        }

        foreach ($this->childrenOfNode($node) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->indexNode($child, $nodeScheme, $currentSegments, $this->sourceByScheme[$nodeScheme] ?? $source);
        }
    }

    private function indexConcept(SubjectVocabularyConcept $concept): void
    {
        foreach ($this->identifierLookupKeys($concept->id, $concept->scheme) as $idKey) {
            $this->byId[$concept->scheme][$idKey] = $concept;
        }

        $classificationCodeKey = $this->lookupKey($concept->classificationCode);
        if ($classificationCodeKey !== null) {
            $this->byNotation[$concept->scheme][$classificationCodeKey] = $concept;
        }

        $pathKey = $this->pathLookupKey($concept->path, $concept->scheme);
        if ($pathKey !== null) {
            $this->byPath[$concept->scheme][$pathKey][$this->conceptKey($concept)] = $concept;
        }

        $leafKey = $this->leafLookupKey($concept->path);
        if ($leafKey !== null) {
            $this->byLeaf[$concept->scheme][$leafKey][$this->conceptKey($concept)] = $concept;
        }

        foreach (array_merge([$concept->label], $concept->synonyms) as $label) {
            $labelKey = $this->labelLookupKey($label);
            if ($labelKey === null) {
                continue;
            }

            $this->globalExactLabel[$labelKey][$this->conceptKey($concept)] = $concept;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<mixed>
     */
    private function childrenOfNode(array $node): array
    {
        $children = $node['children'] ?? [];

        return is_array($children) ? array_values($children) : [];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeText(array $node): ?string
    {
        return $this->filledString(
            $node['text']
                ?? $node['label']
                ?? $node['prefLabel']
                ?? $node['name']
                ?? null
        );
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function classificationCode(array $node): ?string
    {
        return $this->filledString(
            $node['notation']
                ?? $node['classificationCode']
                ?? $node['classification_code']
                ?? null
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function synonyms(array $node): array
    {
        $synonyms = [];
        foreach (['synonyms', 'aliases', 'altLabels', 'alternativeLabels', 'hiddenLabels'] as $key) {
            $rawValues = $node[$key] ?? [];
            if (! is_array($rawValues)) {
                continue;
            }

            foreach ($rawValues as $rawValue) {
                $value = $this->labelFromMixed($rawValue);
                if ($value !== null) {
                    $synonyms[$this->labelLookupKey($value) ?? $value] = $value;
                }
            }
        }

        return array_values($synonyms);
    }

    private function labelFromMixed(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            return $this->filledString($value);
        }

        if (! is_array($value)) {
            return null;
        }

        return $this->filledString(
            $value['value']
                ?? $value['label']
                ?? $value['text']
                ?? $value['prefLabel']
                ?? null
        );
    }

    /**
     * @param  list<string>  $pathSegments
     */
    private function buildPath(string $scheme, array $pathSegments): ?string
    {
        $segments = array_values(array_filter(
            array_map('trim', $pathSegments),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments !== [] && $this->shouldDropLeadingSegment($segments[0], $scheme)) {
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
        return PortalSubjectNormalizer::normalizeScheme($segment) === $scheme;
    }

    private function pathLookupKey(?string $path, ?string $scheme): ?string
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

        return $this->lookupKey(SubjectBreadcrumbPath::normalize(
            implode(PortalSubjectNormalizer::BREADCRUMB_SEPARATOR, $segments),
        ));
    }

    private function leafLookupKey(?string $value): ?string
    {
        return $this->lookupKey(SubjectBreadcrumbPath::leaf($value));
    }

    private function labelLookupKey(?string $label): ?string
    {
        return $this->lookupKey(PortalSubjectNormalizer::normalizeControlledSubjectValue($label));
    }

    private function lookupKey(?string $value): ?string
    {
        $value = $this->filledString($value);
        if ($value === null) {
            return null;
        }

        return mb_strtolower($value);
    }

    /**
     * @return list<string>
     */
    private function identifierLookupKeys(string $identifier, string $scheme): array
    {
        $keys = [$identifier];

        if (isset(self::GCMD_SCHEMES[$scheme])) {
            $uuid = GcmdUriHelper::extractUuid($identifier);
            if ($uuid !== null) {
                $keys[] = GcmdUriHelper::buildConceptUri($uuid);
                $keys[] = 'https://cmr.earthdata.nasa.gov/kms/concept/'.$uuid;
                $keys[] = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/'.$this->gcmdSchemeSegment($scheme).'/'.$uuid;
            }
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $key): ?string => $this->lookupKey($key), $keys),
            static fn (?string $key): bool => $key !== null,
        )));
    }

    private function canonicalConceptId(?string $identifier, string $scheme): ?string
    {
        if ($identifier === null || ! isset(self::GCMD_SCHEMES[$scheme])) {
            return $identifier;
        }

        $uuid = GcmdUriHelper::extractUuid($identifier);

        return $uuid !== null ? GcmdUriHelper::buildConceptUri($uuid) : $identifier;
    }

    private function gcmdSchemeSegment(string $scheme): string
    {
        return match ($scheme) {
            'Platforms' => 'platforms',
            'Instruments' => 'instruments',
            default => 'sciencekeywords',
        };
    }

    /**
     * @return list<string>
     */
    private function pathSegmentsForLookup(?string $path, ?string $scheme): array
    {
        $segments = SubjectBreadcrumbPath::segments($path);
        if ($segments === []) {
            return [];
        }

        if ($scheme !== null && $this->shouldDropLeadingSegment($segments[0], $scheme)) {
            array_shift($segments);
        }

        return array_values(array_filter(
            array_map(static fn (string $segment): string => mb_strtolower(trim($segment)), $segments),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     */
    private function isOrderedSubsequence(array $needles, array $haystack): bool
    {
        if ($needles === [] || count($needles) > count($haystack)) {
            return false;
        }

        $needleIndex = 0;
        $needleCount = count($needles);

        foreach ($haystack as $segment) {
            if ($segment !== $needles[$needleIndex]) {
                continue;
            }

            $needleIndex++;
            if ($needleIndex === $needleCount) {
                return true;
            }
        }

        return false;
    }

    private function conceptKey(SubjectVocabularyConcept $concept): string
    {
        return $concept->scheme.'|'.$concept->id;
    }

    private function configuredString(mixed $configKey): ?string
    {
        if (! is_string($configKey) || trim($configKey) === '') {
            return null;
        }

        return $this->filledString(config($configKey));
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
