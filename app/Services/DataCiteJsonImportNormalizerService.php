<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalizes supported DataCite API read/legacy representations into the
 * canonical DataCite API JSON shape validated by JsonSchemaValidator.
 *
 * Unknown fields are deliberately retained so the strict schema can report
 * them. This service must never be used for export validation.
 */
final class DataCiteJsonImportNormalizerService
{
    /**
     * Known read-only or computed attributes returned by the DataCite API.
     *
     * @var list<string>
     */
    private const READ_ONLY_ATTRIBUTES = [
        'prefix',
        'suffix',
        'url',
        'contentUrl',
        'metadataVersion',
        'schemaVersion',
        'source',
        'isActive',
        'state',
        'reason',
        'registered',
        'published',
        'created',
        'updated',
        'providerId',
        'clientId',
        'agency',
        'event',
        'xml',
        'viewCount',
        'viewsOverTime',
        'downloadCount',
        'downloadsOverTime',
        'referenceCount',
        'citationCount',
        'partCount',
        'versionCount',
        'versionOfCount',
        'references',
        'citations',
        'parts',
        'partOf',
        'versions',
        'versionOf',
        'provider',
        'client',
        'container',
    ];

    /**
     * Computed citation-format names nested below attributes.types.
     *
     * @var list<string>
     */
    private const DERIVED_TYPE_ATTRIBUTES = [
        'ris',
        'bibtex',
        'citeproc',
        'schemaOrg',
    ];

    /**
     * Legacy XML/JSON-LD property names accepted at the import boundary.
     *
     * @var array<string, string>
     */
    private const URI_ALIASES = [
        'schemeURI' => 'schemeUri',
        'rightsURI' => 'rightsUri',
        'awardURI' => 'awardUri',
        'valueURI' => 'valueUri',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalize(array $attributes): array
    {
        $normalizedAttributes = $this->normalizeAliasesAndNulls($attributes);
        $attributes = [];
        foreach ($normalizedAttributes as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('DataCite attributes must be a JSON object.');
            }

            $attributes[$key] = $value;
        }

        foreach (self::READ_ONLY_ATTRIBUTES as $attribute) {
            unset($attributes[$attribute]);
        }

        if (isset($attributes['types']) && is_array($attributes['types'])) {
            foreach (self::DERIVED_TYPE_ATTRIBUTES as $attribute) {
                unset($attributes['types'][$attribute]);
            }
        }

        if (isset($attributes['publicationYear']) && is_int($attributes['publicationYear'])) {
            $attributes['publicationYear'] = (string) $attributes['publicationYear'];
        }

        $attributes = $this->normalizeLegacyDoiIdentifier($attributes);
        $attributes = $this->normalizeAffiliations($attributes);
        $attributes = $this->normalizeGeoLocations($attributes);
        $attributes = $this->removeEmptyDates($attributes);
        $attributes = $this->normalizeRelatedItemYears($attributes);

        return $attributes;
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private function normalizeAliasesAndNulls(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if ($item === null) {
                unset($value[$key]);

                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->normalizeAliasesAndNulls($item);
            }
        }

        if ($isList) {
            return array_values($value);
        }

        foreach (self::URI_ALIASES as $alias => $canonical) {
            if (! array_key_exists($alias, $value)) {
                continue;
            }

            if (array_key_exists($canonical, $value) && $value[$canonical] !== $value[$alias]) {
                throw new \InvalidArgumentException(
                    "Conflicting DataCite properties '{$alias}' and '{$canonical}'."
                );
            }

            $value[$canonical] = $value[$alias];
            unset($value[$alias]);
        }

        return $value;
    }

    /**
     * Accept exports produced before DOI was correctly represented by doi.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeLegacyDoiIdentifier(array $attributes): array
    {
        if (! isset($attributes['identifiers']) || ! is_array($attributes['identifiers'])) {
            return $attributes;
        }

        $doi = is_string($attributes['doi'] ?? null) ? trim($attributes['doi']) : null;
        $identifiers = [];

        foreach ($attributes['identifiers'] as $identifier) {
            if (! is_array($identifier)) {
                $identifiers[] = $identifier;

                continue;
            }

            $type = $identifier['identifierType'] ?? null;
            $value = $identifier['identifier'] ?? null;
            $isDoi = is_string($type)
                && strcasecmp($type, 'DOI') === 0
                && is_string($value)
                && trim($value) !== '';

            if (! $isDoi) {
                $identifiers[] = $identifier;

                continue;
            }

            $identifierDoi = trim($value);
            if ($doi === null || $doi === '') {
                $doi = $identifierDoi;
                $attributes['doi'] = $identifierDoi;

                continue;
            }

            if (strcasecmp($doi, $identifierDoi) !== 0) {
                $identifiers[] = $identifier;
            }
        }

        if ($identifiers === []) {
            unset($attributes['identifiers']);
        } else {
            $attributes['identifiers'] = $identifiers;
        }

        return $attributes;
    }

    /**
     * DataCite omits affiliation details unless affiliation=true was requested.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeAffiliations(array $attributes): array
    {
        foreach (['creators', 'contributors'] as $peopleKey) {
            if (! isset($attributes[$peopleKey]) || ! is_array($attributes[$peopleKey])) {
                continue;
            }

            foreach ($attributes[$peopleKey] as $index => $person) {
                if (! is_array($person) || ! isset($person['affiliation']) || ! is_array($person['affiliation'])) {
                    continue;
                }

                $attributes[$peopleKey][$index]['affiliation'] = array_map(
                    static fn (mixed $affiliation): mixed => is_string($affiliation)
                        ? ['name' => $affiliation]
                        : $affiliation,
                    $person['affiliation'],
                );
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeGeoLocations(array $attributes): array
    {
        if (! isset($attributes['geoLocations']) || ! is_array($attributes['geoLocations'])) {
            return $attributes;
        }

        foreach ($attributes['geoLocations'] as $index => $geoLocation) {
            if (! is_array($geoLocation)) {
                continue;
            }

            foreach (['geoLocationPoint', 'geoLocationBox'] as $coordinateObject) {
                if (isset($geoLocation[$coordinateObject]) && is_array($geoLocation[$coordinateObject])) {
                    $geoLocation[$coordinateObject] = $this->normalizeCoordinateObject($geoLocation[$coordinateObject]);
                }
            }

            if (isset($geoLocation['geoLocationPolygon']) && is_array($geoLocation['geoLocationPolygon'])) {
                $geoLocation['geoLocationPolygon'] = $this->normalizePolygon($geoLocation['geoLocationPolygon']);
            }

            $attributes['geoLocations'][$index] = $geoLocation;
        }

        return $attributes;
    }

    /**
     * @param  array<int|string, mixed>  $polygon
     * @return array<int, mixed>
     */
    private function normalizePolygon(array $polygon): array
    {
        if (! array_is_list($polygon) && isset($polygon['polygonPoints']) && is_array($polygon['polygonPoints'])) {
            $normalized = [];

            foreach ($polygon['polygonPoints'] as $point) {
                $normalized[] = [
                    'polygonPoint' => is_array($point) ? $this->normalizeCoordinateObject($point) : $point,
                ];
            }

            if (isset($polygon['inPolygonPoint'])) {
                $normalized[] = [
                    'inPolygonPoint' => is_array($polygon['inPolygonPoint'])
                        ? $this->normalizeCoordinateObject($polygon['inPolygonPoint'])
                        : $polygon['inPolygonPoint'],
                ];
            }

            return $normalized;
        }

        foreach ($polygon as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['polygonPoint', 'inPolygonPoint'] as $pointKey) {
                if (isset($entry[$pointKey]) && is_array($entry[$pointKey])) {
                    $polygon[$index][$pointKey] = $this->normalizeCoordinateObject($entry[$pointKey]);
                }
            }
        }

        return array_values($polygon);
    }

    /**
     * @param  array<string, mixed>  $coordinates
     * @return array<string, mixed>
     */
    private function normalizeCoordinateObject(array $coordinates): array
    {
        foreach ($coordinates as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '' && preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/D', $trimmed) === 1) {
                $coordinates[$key] = (float) $trimmed;
            }
        }

        return $coordinates;
    }

    /**
     * Preserve the established upload behavior for legacy empty date entries.
     * Missing and non-string values remain untouched so schema validation can
     * still report malformed data.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function removeEmptyDates(array $attributes): array
    {
        if (! isset($attributes['dates']) || ! is_array($attributes['dates'])) {
            return $attributes;
        }

        $dates = array_values(array_filter(
            $attributes['dates'],
            static fn (mixed $date): bool => ! is_array($date)
                || ! is_string($date['date'] ?? null)
                || trim($date['date']) !== '',
        ));

        if ($dates === []) {
            unset($attributes['dates']);
        } else {
            $attributes['dates'] = $dates;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeRelatedItemYears(array $attributes): array
    {
        if (! isset($attributes['relatedItems']) || ! is_array($attributes['relatedItems'])) {
            return $attributes;
        }

        foreach ($attributes['relatedItems'] as $index => $relatedItem) {
            if (is_array($relatedItem) && isset($relatedItem['publicationYear']) && is_int($relatedItem['publicationYear'])) {
                $attributes['relatedItems'][$index]['publicationYear'] = (string) $relatedItem['publicationYear'];
            }
        }

        return $attributes;
    }
}
