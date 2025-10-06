<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadXmlRequest;
use App\Models\ResourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

class UploadXmlController extends Controller
{
    /**
     * @var array<string, array{value: string, rorId: string}>
     */
    private array $affiliationMap = [];

    private bool $affiliationMapLoaded = false;

    public function __invoke(UploadXmlRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $contents = $validated['file']->get();

        $reader = XmlReader::fromString($contents);
        $doi = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="identifier" and @identifierType="DOI"]'),
        );
        $year = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="publicationYear"]'),
        );
        $version = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="version"]'),
        );
        $language = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="language"]'),
        );
        $authors = $this->extractAuthors($reader);

        $rightsElements = $reader
            ->xpathElement('//*[local-name()="rightsList"]/*[local-name()="rights"]')
            ->get();
        $licenses = [];

        foreach ($rightsElements as $element) {
            $identifier = $element->getAttribute('rightsIdentifier');
            if ($identifier) {
                $licenses[] = $identifier;
            }
        }

        $titleElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="titles"]/*[local-name()="title"]')
            ->get();
        $titles = [];

        foreach ($titleElements as $element) {
            $titleType = $element->getAttribute('titleType');
            $titles[] = [
                'title' => $element->getContent(),
                'titleType' => $titleType ? Str::kebab($titleType) : 'main-title',
            ];
        }

        $mainTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] === 'main-title'
        ));
        $otherTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] !== 'main-title'
        ));
        $titles = array_merge($mainTitles, $otherTitles);

        $resourceTypeElement = $this->extractFirstElementFromQuery(
            $reader->xpathElement('//*[local-name()="resourceType"]'),
        );
        $resourceTypeName = $resourceTypeElement?->getAttribute('resourceTypeGeneral');
        $resourceType = null;

        if ($resourceTypeName !== null) {
            $resourceTypeModel = ResourceType::whereRaw('LOWER(name) = ?', [Str::lower($resourceTypeName)])->first();
            $resourceType = $resourceTypeModel?->id;
        }

        return response()->json([
            'doi' => $doi,
            'year' => $year,
            'version' => $version,
            'language' => $language,
            'resourceType' => $resourceType !== null ? (string) $resourceType : null,
            'titles' => $titles,
            'licenses' => $licenses,
            'authors' => $authors,
        ]);
    }

    private function extractFirstStringFromQuery(mixed $query): ?string
    {
        if (! is_object($query) || ! method_exists($query, 'first')) {
            return null;
        }

        $value = $query->first();

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function extractFirstElementFromQuery(mixed $query): ?Element
    {
        if (! is_object($query) || ! method_exists($query, 'first')) {
            return null;
        }

        $value = $query->first();

        return $value instanceof Element ? $value : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractAuthors(XmlReader $reader): array
    {
        $creatorElements = $reader
            ->xpathElement('/*[local-name()="resource"]/*[local-name()="creators"]/*[local-name()="creator"]')
            ->get();

        $authors = [];

        foreach ($creatorElements as $creator) {
            $content = $creator->getContent();

            if (! is_array($content)) {
                continue;
            }

            $creatorName = $this->firstElement($content, 'creatorName');
            $nameType = $creatorName?->getAttribute('nameType');
            $type = is_string($nameType) && Str::lower($nameType) === 'organizational' ? 'institution' : 'person';

            $affiliations = $this->extractAffiliations($content);

            if ($type === 'institution') {
                $authors[] = [
                    'type' => 'institution',
                    'institutionName' => $this->stringValue($creatorName) ?? '',
                    'affiliations' => $affiliations,
                ];

                continue;
            }

            $givenName = $this->stringValue($this->firstElement($content, 'givenName'));
            $familyName = $this->stringValue($this->firstElement($content, 'familyName'));

            if ((! $givenName || ! $familyName) && $creatorName instanceof Element) {
                $resolved = $this->splitCreatorName($this->stringValue($creatorName));
                $familyName ??= $resolved['familyName'];
                $givenName ??= $resolved['givenName'];
            }

            $authors[] = [
                'type' => 'person',
                'orcid' => $this->extractOrcid($content),
                'firstName' => $givenName ?? '',
                'lastName' => $familyName ?? ($this->stringValue($creatorName) ?? ''),
                'affiliations' => $affiliations,
            ];
        }

        return $authors;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<int, array{value: string, rorId: string|null}>
     */
    private function extractAffiliations(array $content): array
    {
        $affiliationElements = $this->allElements($content, 'affiliation');

        /** @var array<int, array{value: string, rorId: string|null}> $affiliations */
        $affiliations = [];

        foreach ($affiliationElements as $element) {
            $rawValue = $this->stringValue($element);

            $identifier = $element->getAttribute('affiliationIdentifier');
            $scheme = $element->getAttribute('affiliationIdentifierScheme');

            $resolved = null;

            if (is_string($identifier) && $identifier !== '') {
                $resolved = $this->resolveAffiliationByRor($identifier, is_string($scheme) ? $scheme : null, $rawValue);
            }

            if ($resolved !== null) {
                $affiliations[] = $resolved;

                continue;
            }

            if (is_string($rawValue) && $rawValue !== '') {
                $affiliations[] = [
                    'value' => $rawValue,
                    'rorId' => null,
                ];
            }
        }

        return $affiliations;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function extractOrcid(array $content): ?string
    {
        $identifierElements = $this->allElements($content, 'nameIdentifier');

        foreach ($identifierElements as $element) {
            $scheme = $element->getAttribute('nameIdentifierScheme');

            if (is_string($scheme) && Str::lower($scheme) !== 'orcid') {
                continue;
            }

            $value = $this->stringValue($element);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $orcid = $this->canonicaliseOrcid($value);

            if ($orcid !== null) {
                return $orcid;
            }
        }

        return null;
    }

    private function canonicaliseOrcid(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^https?://orcid\.org/#i', $trimmed) === 1) {
            $path = parse_url($trimmed, PHP_URL_PATH);
            $identifier = is_string($path) ? trim($path, '/') : '';
        } else {
            $identifier = trim($trimmed, '/');
        }

        if ($identifier === '') {
            return null;
        }

        return 'https://orcid.org/' . strtoupper($identifier);
    }

    /**
     * @return array{value: string, rorId: string}|null
     */
    private function resolveAffiliationByRor(string $identifier, ?string $scheme, ?string $fallback): ?array
    {
        if (! $this->isRorIdentifier($identifier, $scheme)) {
            return null;
        }

        $canonical = $this->canonicaliseRorId($identifier);

        if ($canonical === null) {
            return null;
        }

        if (! $this->affiliationMapLoaded) {
            $this->loadAffiliationMap();
        }

        $resolved = $this->affiliationMap[$canonical] ?? null;

        if ($resolved !== null) {
            return $resolved;
        }

        $label = is_string($fallback) && $fallback !== '' ? $fallback : $canonical;

        return [
            'value' => $label,
            'rorId' => $canonical,
        ];
    }

    private function isRorIdentifier(string $identifier, ?string $scheme): bool
    {
        if (is_string($scheme) && Str::lower($scheme) === 'ror') {
            return true;
        }

        return Str::contains(Str::lower($identifier), 'ror.org');
    }

    private function canonicaliseRorId(string $identifier): ?string
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return null;
        }

        $parsed = parse_url($trimmed);

        if ($parsed !== false && isset($parsed['path'])) {
            $host = isset($parsed['host']) ? Str::lower($parsed['host']) : 'ror.org';
            $path = trim((string) $parsed['path'], '/');

            if ($path === '') {
                return null;
            }

            return 'https://' . $host . '/' . Str::lower($path);
        }

        $path = Str::lower(trim($trimmed, '/'));

        if ($path === '') {
            return null;
        }

        return 'https://ror.org/' . $path;
    }

    private function loadAffiliationMap(): void
    {
        $this->affiliationMapLoaded = true;

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists('ror/ror-affiliations.json')) {
                return;
            }

            $contents = $disk->get('ror/ror-affiliations.json');

            if (! is_string($contents)) {
                return;
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                return;
            }

            foreach ($decoded as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $label = isset($entry['prefLabel']) && is_string($entry['prefLabel'])
                    ? trim($entry['prefLabel'])
                    : '';
                $rorId = isset($entry['rorId']) && is_string($entry['rorId']) ? $this->canonicaliseRorId($entry['rorId']) : null;

                if ($label === '' || $rorId === null) {
                    continue;
                }

                $this->affiliationMap[$rorId] = [
                    'value' => $label,
                    'rorId' => $rorId,
                ];
            }
        } catch (JsonException) {
            // Ignore invalid cache contents and fall back to raw affiliation labels.
        }
    }

    /**
     * @param array<string, mixed> $content
     */
    private function firstElement(array $content, string $key): ?Element
    {
        $elements = $this->allElements($content, $key);

        return $elements[0] ?? null;
    }

    /**
     * @param array<string, mixed> $content
     * @return Element[]
     */
    private function allElements(array $content, string $key): array
    {
        if (! array_key_exists($key, $content)) {
            return [];
        }

        return $this->normaliseToElementList($content[$key]);
    }

    /**
     * @return Element[]
     */
    private function normaliseToElementList(mixed $value): array
    {
        if ($value instanceof Element) {
            $content = $value->getContent();

            if (is_array($content)) {
                $elements = [];

                foreach ($content as $nested) {
                    array_push($elements, ...$this->normaliseToElementList($nested));
                }

                return $elements ?: [$value];
            }

            return [$value];
        }

        if (is_array($value)) {
            $elements = [];

            foreach ($value as $nested) {
                array_push($elements, ...$this->normaliseToElementList($nested));
            }

            return $elements;
        }

        return [];
    }

    private function stringValue(?Element $element): ?string
    {
        if (! $element instanceof Element) {
            return null;
        }

        $content = $element->getContent();

        if (is_string($content)) {
            $trimmed = trim($content);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $value) {
                $text = $this->stringValue($value instanceof Element ? $value : null);

                if ($text !== null) {
                    $parts[] = $text;
                }
            }

            if (! empty($parts)) {
                return trim(implode(' ', $parts));
            }
        }

        return null;
    }

    /**
     * @return array{givenName: string|null, familyName: string|null}
     */
    private function splitCreatorName(?string $name): array
    {
        if (! is_string($name) || $name === '') {
            return ['givenName' => null, 'familyName' => null];
        }

        $parts = array_map('trim', explode(',', $name, 2));

        if (count($parts) === 2) {
            return [
                'familyName' => $parts[0] !== '' ? $parts[0] : null,
                'givenName' => $parts[1] !== '' ? $parts[1] : null,
            ];
        }

        return [
            'familyName' => $name,
            'givenName' => null,
        ];
    }
}
