<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;
use App\Support\OrcidNormalizer;

/**
 * Generates Schema.org-compatible JSON-LD for inline embedding on landing pages.
 *
 * Follows ESIP Science-on-Schema.org best practices for Google Dataset Search
 * discoverability. Output is intended for <script type="application/ld+json"> tags.
 *
 * @see https://github.com/ESIPFed/science-on-schema.org
 * @see https://developers.google.com/search/docs/appearance/structured-data/dataset
 */
class SchemaOrgJsonLdExporter
{
    /**
     * Controlled vocabulary subject schemes that should be emitted as Schema.org DefinedTerm.
     *
     * Subjects from these schemes include a definedTermSet, termCode, and url.
     * Free-text subjects (not in this list) are emitted as plain strings.
     */
    private const CONTROLLED_VOCABULARY_SCHEMES = [
        'Science Keywords',
        'Platforms',
        'Instruments',
        'EPOS MSL vocabulary',
        'GEMET - GEneral Multilingual Environmental Thesaurus',
        'International Chronostratigraphic Chart',
        'Analytical Methods for Geochemistry and Cosmochemistry',
        'European Science Vocabulary (EuroSciVoc)',
    ];

    /**
     * Export a Resource as Schema.org Dataset JSON-LD.
     *
     * @return array<string, mixed>
     */
    public function export(Resource $resource): array
    {
        $jsonExporter = new DataCiteJsonExporter;
        $dataCiteJson = $jsonExporter->export($resource);
        $attributes = $dataCiteJson['data']['attributes'];

        $jsonLd = [
            '@context' => 'https://schema.org/',
            '@type' => 'Dataset',
            'isAccessibleForFree' => true,
        ];

        // @id and url from DOI
        if (isset($attributes['doi'])) {
            $doiUrl = 'https://doi.org/' . $attributes['doi'];
            $jsonLd['@id'] = $doiUrl;
            $jsonLd['url'] = $doiUrl;
            $jsonLd['identifier'] = $this->buildDoiIdentifier($attributes['doi']);
        }

        // Name from main title
        $jsonLd['name'] = $this->extractMainTitle($attributes['titles'] ?? []);

        // Description from abstract
        $description = $this->extractAbstract($attributes['descriptions'] ?? []);
        if ($description !== null) {
            $jsonLd['description'] = $description;
        }

        // Creators with @list for order preservation
        if (! empty($attributes['creators'])) {
            $jsonLd['creator'] = ['@list' => $this->transformCreators($attributes['creators'])];
        }

        // Publisher
        $jsonLd['publisher'] = $this->transformPublisher($attributes['publisher'] ?? []);

        // Provider references same organization as publisher
        if (isset($jsonLd['publisher']['@id'])) {
            $jsonLd['provider'] = ['@id' => $jsonLd['publisher']['@id']];
        }

        // Dates
        $this->applyDates($jsonLd, $attributes['dates'] ?? []);

        // Publication year as datePublished fallback
        if (! isset($jsonLd['datePublished']) && ! empty($attributes['publicationYear'])) {
            $jsonLd['datePublished'] = $attributes['publicationYear'];
        }

        // Keywords (mixed plain text and DefinedTerm)
        if (! empty($attributes['subjects'])) {
            $jsonLd['keywords'] = $this->transformKeywords($attributes['subjects']);
        }

        // License
        if (! empty($attributes['rightsList'])) {
            $jsonLd['license'] = $this->transformLicense($attributes['rightsList']);
        }

        // Spatial coverage
        if (! empty($attributes['geoLocations'])) {
            $spatial = $this->transformSpatialCoverage($attributes['geoLocations']);
            if ($spatial !== null) {
                $jsonLd['spatialCoverage'] = $spatial;
            }
        }

        // Funding
        if (! empty($attributes['fundingReferences'])) {
            $jsonLd['funding'] = $this->transformFunding($attributes['fundingReferences']);
        }

        // Version
        if (! empty($attributes['version'])) {
            $jsonLd['version'] = $attributes['version'];
        }

        // Citation: related items become schema:citation CreativeWork entries
        if (! empty($attributes['relatedItems'])) {
            $citations = $this->transformCitations($attributes['relatedItems']);
            if ($citations !== []) {
                $jsonLd['citation'] = $citations;
            }
        }

        // subjectOf: cross-links to other metadata formats via DataCite Content Negotiation
        if (isset($attributes['doi'])) {
            $jsonLd['subjectOf'] = $this->buildSubjectOf($attributes['doi']);
        }

        return $jsonLd;
    }

    /**
     * Build a PropertyValue identifier for the DOI (ESIP recommendation).
     *
     * @return array<string, string>
     */
    private function buildDoiIdentifier(string $doi): array
    {
        return [
            '@id' => 'https://doi.org/' . $doi,
            '@type' => 'PropertyValue',
            'propertyID' => 'https://registry.identifiers.org/registry/doi',
            'value' => 'doi:' . $doi,
            'url' => 'https://doi.org/' . $doi,
        ];
    }

    /**
     * Strip resolver URL prefixes (`https://doi.org/`, `http://dx.doi.org/`)
     * and the `doi:` scheme prefix from a DOI string. Mirrors the backend
     * behaviour of {@see DataCiteApiService::normalizeDoi()} but preserves the
     * original casing so the resulting identifier matches what users entered.
     */
    private function stripDoiPrefix(string $doi): string
    {
        $value = trim($doi);

        if (preg_match('/^https?:\/\/(?:dx\.)?doi\.org\/?(.*)$/i', $value, $matches) === 1) {
            $value = $matches[1];
        }

        if (preg_match('/^doi:(.+)$/i', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return trim($value);
    }

    /**
     * URL-encode the DOI path while preserving the slash between the prefix
     * and suffix, so e.g. `10.1234/foo bar` becomes `10.1234/foo%20bar`.
     */
    private function encodeDoiPath(string $doi): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $doi)));
    }

    /**
     * Extract the main title from the titles array.
     *
     * @param  array<int, array<string, mixed>>  $titles
     */
    private function extractMainTitle(array $titles): string
    {
        // First title without titleType, or first title at all
        foreach ($titles as $title) {
            if (! isset($title['titleType'])) {
                return $title['title'];
            }
        }

        return $titles[0]['title'] ?? 'Untitled';
    }

    /**
     * Extract the abstract from descriptions.
     *
     * @param  array<int, array<string, mixed>>  $descriptions
     */
    private function extractAbstract(array $descriptions): ?string
    {
        foreach ($descriptions as $desc) {
            if (($desc['descriptionType'] ?? '') === 'Abstract') {
                return $desc['description'];
            }
        }

        return null;
    }

    /**
     * Transform creators to Schema.org Person/Organization types.
     *
     * @param  array<int, array<string, mixed>>  $creators
     * @return array<int, array<string, mixed>>
     */
    private function transformCreators(array $creators): array
    {
        return array_map(fn (array $creator): array => $this->transformSingleCreator($creator), $creators);
    }

    /**
     * @param  array<string, mixed>  $creator
     * @return array<string, mixed>
     */
    private function transformSingleCreator(array $creator): array
    {
        $nameType = $creator['nameType'] ?? 'Personal';

        if ($nameType === 'Organizational') {
            return [
                '@type' => 'Organization',
                'name' => $creator['name'],
            ];
        }

        $person = [
            '@type' => 'Person',
            'name' => $creator['name'],
        ];

        if (isset($creator['givenName'])) {
            $person['givenName'] = $creator['givenName'];
        }

        if (isset($creator['familyName'])) {
            $person['familyName'] = $creator['familyName'];
        }

        // ORCID identifier
        if (! empty($creator['nameIdentifiers'])) {
            foreach ($creator['nameIdentifiers'] as $ni) {
                if (strtoupper($ni['nameIdentifierScheme'] ?? '') === 'ORCID') {
                    $orcidUrl = OrcidNormalizer::toUrl($ni['nameIdentifier']);
                    $person['@id'] = $orcidUrl;
                    $person['identifier'] = [
                        '@id' => $orcidUrl,
                        '@type' => 'PropertyValue',
                        'propertyID' => 'https://registry.identifiers.org/registry/orcid',
                        'value' => 'orcid:' . OrcidNormalizer::extractBareId($ni['nameIdentifier']),
                        'url' => $orcidUrl,
                    ];
                    break;
                }
            }
        }

        // Affiliations
        if (! empty($creator['affiliation'])) {
            $affiliations = array_map(fn (array $aff): array => $this->transformCreatorAffiliation($aff), $creator['affiliation']);
            $person['affiliation'] = count($affiliations) === 1 ? $affiliations[0] : $affiliations;
        }

        return $person;
    }

    /**
     * @param  array<string, string|null>  $affiliation
     * @return array<string, string>
     */
    private function transformCreatorAffiliation(array $affiliation): array
    {
        $org = [
            '@type' => 'Organization',
            'name' => $affiliation['name'] ?? '',
        ];

        if (! empty($affiliation['affiliationIdentifier'])) {
            $org['@id'] = $affiliation['affiliationIdentifier'];
        }

        return $org;
    }

    /**
     * Transform publisher to Schema.org Organization.
     *
     * @param  array<string, string|null>  $publisher
     * @return array<string, string>
     */
    private function transformPublisher(array $publisher): array
    {
        $org = [
            '@type' => 'Organization',
            'name' => $publisher['name'] ?? 'GFZ Data Services',
        ];

        // Use ROR as @id if publisher has a ROR identifier
        if (isset($publisher['publisherIdentifierScheme']) && strtoupper($publisher['publisherIdentifierScheme']) === 'ROR') {
            $org['@id'] = $publisher['publisherIdentifier'] ?? '';
        } elseif (! empty($publisher['publisherIdentifier'])) {
            $org['@id'] = $publisher['publisherIdentifier'];
        }

        return $org;
    }

    /**
     * Apply date mappings from DataCite dateTypes to Schema.org properties.
     *
     * @param  array<string, mixed>  $jsonLd
     * @param  array<int, array<string, mixed>>  $dates
     */
    private function applyDates(array &$jsonLd, array $dates): void
    {
        foreach ($dates as $date) {
            $dateType = $date['dateType'] ?? '';
            $dateValue = $date['date'] ?? '';

            match ($dateType) {
                'Issued' => $jsonLd['datePublished'] = $dateValue,
                'Created' => $jsonLd['dateCreated'] = $dateValue,
                'Updated' => $jsonLd['dateModified'] = $dateValue,
                'Collected' => $jsonLd['temporalCoverage'] = $this->formatTemporalCoverage($dateValue),
                default => null,
            };
        }
    }

    /**
     * Format temporal coverage as ISO 8601 interval.
     */
    private function formatTemporalCoverage(string $dateValue): string
    {
        // If already contains a slash (date range), return as-is
        if (str_contains($dateValue, '/')) {
            return $dateValue;
        }

        // Single date becomes open-ended range
        return $dateValue . '/..';
    }

    /**
     * Transform subjects to mixed keywords (text + DefinedTerm).
     *
     * @param  array<int, array<string, mixed>>  $subjects
     * @return array<int, string|array<string, mixed>>
     */
    private function transformKeywords(array $subjects): array
    {
        $keywords = [];

        foreach ($subjects as $subject) {
            $scheme = $subject['subjectScheme'] ?? null;

            if ($scheme !== null && in_array($scheme, self::CONTROLLED_VOCABULARY_SCHEMES, true)) {
                $definedTerm = [
                    '@type' => 'DefinedTerm',
                    'name' => $subject['subject'],
                ];

                if (! empty($subject['schemeUri'])) {
                    $definedTerm['inDefinedTermSet'] = $subject['schemeUri'];
                }

                if (! empty($subject['valueUri'])) {
                    $definedTerm['url'] = $subject['valueUri'];
                }

                $keywords[] = $definedTerm;
            } else {
                $keywords[] = $subject['subject'];
            }
        }

        return $keywords;
    }

    /**
     * Transform rights to license URIs (prefer SPDX).
     *
     * @param  array<int, array<string, string>>  $rightsList
     * @return string|array<int, string>
     */
    private function transformLicense(array $rightsList): string|array
    {
        $uris = [];

        foreach ($rightsList as $rights) {
            // Prefer SPDX scheme URI if available
            if (! empty($rights['schemeURI'])) {
                $spdxUri = rtrim($rights['schemeURI'], '/');
                if (! empty($rights['rightsIdentifier'])) {
                    $spdxUri .= '/' . $rights['rightsIdentifier'];
                }
                $uris[] = $spdxUri;
            }

            // Also add the original rights URI if different
            if (! empty($rights['rightsURI'])) {
                $originalUri = $rights['rightsURI'];
                if (! in_array($originalUri, $uris, true)) {
                    $uris[] = $originalUri;
                }
            }
        }

        if (count($uris) === 0) {
            return $rightsList[0]['rights'] ?? '';
        }

        return count($uris) === 1 ? $uris[0] : $uris;
    }

    /**
     * Transform geo locations to Schema.org spatialCoverage.
     *
     * @param  array<int, array<string, mixed>>  $geoLocations
     * @return array<string, mixed>|null
     */
    private function transformSpatialCoverage(array $geoLocations): ?array
    {
        $geos = [];

        foreach ($geoLocations as $geo) {
            $place = [
                '@type' => 'Place',
            ];

            if (isset($geo['geoLocationPlace'])) {
                $place['name'] = $geo['geoLocationPlace'];
            }

            if (isset($geo['geoLocationPoint'])) {
                $place['geo'] = [
                    '@type' => 'GeoCoordinates',
                    'latitude' => $geo['geoLocationPoint']['pointLatitude'],
                    'longitude' => $geo['geoLocationPoint']['pointLongitude'],
                ];
            } elseif (isset($geo['geoLocationBox'])) {
                $box = $geo['geoLocationBox'];
                $place['geo'] = [
                    '@type' => 'GeoShape',
                    'box' => implode(' ', [
                        $box['southBoundLatitude'],
                        $box['westBoundLongitude'],
                        $box['northBoundLatitude'],
                        $box['eastBoundLongitude'],
                    ]),
                ];
            } elseif (isset($geo['geoLocationPolygon'])) {
                $points = $geo['geoLocationPolygon']['polygonPoints'] ?? [];
                $pairs = array_map(
                    fn (array $p): string => $p['pointLatitude'] . ' ' . $p['pointLongitude'],
                    $points
                );
                $place['geo'] = [
                    '@type' => 'GeoShape',
                    'polygon' => implode(' ', $pairs),
                ];
            }

            $geos[] = $place;
        }

        if (empty($geos)) {
            return null;
        }

        /** @var array<string, mixed> */
        return count($geos) === 1 ? $geos[0] : $geos;
    }

    /**
     * Transform funding references to Schema.org MonetaryGrant.
     *
     * @param  array<int, array<string, mixed>>  $fundingReferences
     * @return array<int, array<string, mixed>>
     */
    private function transformFunding(array $fundingReferences): array
    {
        return array_map(function (array $funding): array {
            $grant = [
                '@type' => 'MonetaryGrant',
            ];

            if (isset($funding['awardNumber'])) {
                $grant['identifier'] = $funding['awardNumber'];
            }

            if (isset($funding['awardTitle'])) {
                $grant['name'] = $funding['awardTitle'];
            }

            $funder = [
                '@type' => 'Organization',
                'name' => $funding['funderName'],
            ];

            if (isset($funding['funderIdentifier'])) {
                $funder['identifier'] = $funding['funderIdentifier'];
            }

            $grant['funder'] = $funder;

            return $grant;
        }, $fundingReferences);
    }

    /**
     * Transform DataCite relatedItems into Schema.org citation CreativeWork entries.
     *
     * @param  array<int, array<string, mixed>>  $relatedItems
     * @return array<int, array<string, mixed>>
     */
    private function transformCitations(array $relatedItems): array
    {
        $citations = [];
        foreach ($relatedItems as $ri) {
            $entry = ['@type' => 'CreativeWork'];

            // Name (MainTitle preferred — explicit MainTitle wins, missing
            // titleType is treated as MainTitle, otherwise fall back to first.)
            if (is_array($ri['titles'] ?? null)) {
                foreach ($ri['titles'] as $title) {
                    if (! is_array($title) || ! isset($title['title']) || ! is_string($title['title'])) {
                        continue;
                    }
                    $type = $title['titleType'] ?? null;
                    if ($type === null || $type === 'MainTitle') {
                        $entry['name'] = $title['title'];
                        break;
                    }
                }
                if (! isset($entry['name'])) {
                    $first = $ri['titles'][0] ?? null;
                    if (is_array($first) && isset($first['title']) && is_string($first['title'])) {
                        $entry['name'] = $first['title'];
                    }
                }
            }

            // Identifier (DOI → URL, others → PropertyValue)
            if (is_array($ri['relatedItemIdentifier'] ?? null)) {
                $idVal = $ri['relatedItemIdentifier']['relatedItemIdentifier'] ?? null;
                $idType = $ri['relatedItemIdentifier']['relatedItemIdentifierType'] ?? null;
                if (is_string($idVal) && $idVal !== '') {
                    if ($idType === 'DOI') {
                        // The stored identifier may already be a resolver URL
                        // (`https://doi.org/...`, `http://dx.doi.org/...`) or a
                        // `doi:`-prefixed value. Strip those before composing the
                        // canonical resolver URL so we never emit
                        // `https://doi.org/https://doi.org/...`.
                        $bareDoi = $this->stripDoiPrefix($idVal);
                        $entry['@id'] = 'https://doi.org/' . $this->encodeDoiPath($bareDoi);
                        $entry['identifier'] = [
                            '@type' => 'PropertyValue',
                            'propertyID' => 'https://registry.identifiers.org/registry/doi',
                            'value' => 'doi:' . $bareDoi,
                        ];
                    } elseif ($idType === 'URL') {
                        $entry['url'] = $idVal;
                    } else {
                        $entry['identifier'] = [
                            '@type' => 'PropertyValue',
                            'propertyID' => is_string($idType) ? $idType : 'identifier',
                            'value' => $idVal,
                        ];
                    }
                }
            }

            // Authors
            if (is_array($ri['creators'] ?? null) && $ri['creators'] !== []) {
                $authors = [];
                foreach ($ri['creators'] as $creator) {
                    if (! is_array($creator)) {
                        continue;
                    }
                    $author = [
                        '@type' => ($creator['nameType'] ?? 'Personal') === 'Organizational' ? 'Organization' : 'Person',
                        'name' => $creator['name'] ?? '',
                    ];
                    if (isset($creator['givenName'])) {
                        $author['givenName'] = $creator['givenName'];
                    }
                    if (isset($creator['familyName'])) {
                        $author['familyName'] = $creator['familyName'];
                    }
                    $authors[] = $author;
                }
                if ($authors !== []) {
                    $entry['author'] = count($authors) === 1 ? $authors[0] : $authors;
                }
            }

            // Publisher
            if (isset($ri['publisher']) && is_string($ri['publisher']) && $ri['publisher'] !== '') {
                $entry['publisher'] = [
                    '@type' => 'Organization',
                    'name' => $ri['publisher'],
                ];
            }

            // Date published
            if (isset($ri['publicationYear'])) {
                $entry['datePublished'] = (string) $ri['publicationYear'];
            }

            // Bibliographic details
            $bibParts = [];
            if (isset($ri['volume'])) {
                $bibParts[] = 'Vol. ' . $ri['volume'];
            }
            if (isset($ri['issue'])) {
                $bibParts[] = 'Issue ' . $ri['issue'];
            }
            if (isset($ri['firstPage'])) {
                $pages = (string) $ri['firstPage'];
                if (isset($ri['lastPage'])) {
                    $pages .= '-' . (string) $ri['lastPage'];
                }
                $bibParts[] = 'pp. ' . $pages;
            }
            if ($bibParts !== []) {
                $entry['description'] = implode(', ', $bibParts);
            }

            $citations[] = $entry;
        }

        return $citations;
    }

    /**
     * Build subjectOf cross-links to alternative metadata formats.
     *
     * Uses DataCite Content Negotiation URLs which are publicly accessible,
     * since this JSON-LD is embedded on public landing pages for SEO.
     *
     * @see https://support.datacite.org/docs/datacite-content-resolver
     *
     * @return array<int, array<string, string>>
     */
    private function buildSubjectOf(string $doi): array
    {
        $encodedDoi = rawurlencode($doi);

        return [
            [
                '@type' => 'DataDownload',
                'name' => 'DataCite XML metadata',
                'encodingFormat' => 'application/vnd.datacite.datacite+xml',
                'contentUrl' => "https://data.datacite.org/application/vnd.datacite.datacite%2Bxml/{$encodedDoi}",
            ],
            [
                '@type' => 'DataDownload',
                'name' => 'DataCite JSON metadata',
                'encodingFormat' => 'application/vnd.datacite.datacite+json',
                'contentUrl' => "https://data.datacite.org/application/vnd.datacite.datacite%2Bjson/{$encodedDoi}",
            ],
        ];
    }
}
