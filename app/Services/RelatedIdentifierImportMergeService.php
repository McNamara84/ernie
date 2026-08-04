<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Merges related identifiers in source priority order without creating duplicates.
 */
final readonly class RelatedIdentifierImportMergeService
{
    /** @var list<string> */
    private const OPTIONAL_FIELDS = [
        'resourceTypeGeneral',
        'relationTypeInformation',
        'relatedMetadataScheme',
        'schemeUri',
        'schemeType',
        'citationLabel',
    ];

    public function __construct(
        private RelatedIdentifierTypeResolverService $typeResolver,
    ) {}

    /**
     * @param  array<int, mixed>  $jsonRelatedIdentifiers
     * @param  array<int, mixed>  $xmlRelatedIdentifiers
     * @param  array<int, mixed>  $legacyRelatedIdentifiers
     * @return list<array<string, string>>
     */
    public function merge(
        array $jsonRelatedIdentifiers,
        array $xmlRelatedIdentifiers,
        array $legacyRelatedIdentifiers = [],
    ): array {
        $json = $this->canonicalizeList($jsonRelatedIdentifiers, true);
        $xml = $this->canonicalizeList($xmlRelatedIdentifiers, false);
        $legacy = $this->canonicalizeList($legacyRelatedIdentifiers, false);
        $usedXmlIndexes = [];

        /** @var list<array<string, string|int>> $repairedJson */
        $repairedJson = [];

        foreach ($json as $relatedIdentifier) {
            if ($this->identifier($relatedIdentifier) !== '') {
                $repairedJson[] = $relatedIdentifier;

                continue;
            }

            $xmlIndex = $this->matchingXmlIndex($relatedIdentifier, $xml, $usedXmlIndexes);

            if ($xmlIndex === null) {
                continue;
            }

            $repairedJson[] = $this->supplement($relatedIdentifier, $xml[$xmlIndex]);
            $usedXmlIndexes[$xmlIndex] = true;
        }

        /** @var list<array<string, string>> $merged */
        $merged = [];
        /** @var array<string, int> $keyIndexes */
        $keyIndexes = [];

        foreach ($repairedJson as $relatedIdentifier) {
            $this->appendOrSupplement($merged, $keyIndexes, $relatedIdentifier);
        }

        foreach ($xml as $xmlIndex => $relatedIdentifier) {
            if (isset($usedXmlIndexes[$xmlIndex])) {
                continue;
            }

            $this->appendOrSupplement($merged, $keyIndexes, $relatedIdentifier);
        }

        foreach ($legacy as $relatedIdentifier) {
            $this->appendOrSupplement($merged, $keyIndexes, $relatedIdentifier);
        }

        return $merged;
    }

    /**
     * @param  array<int, mixed>  $records
     * @return list<array<string, string|int>>
     */
    private function canonicalizeList(array $records, bool $keepIncomplete): array
    {
        $canonical = [];

        foreach (array_values($records) as $position => $record) {
            if (! is_array($record)) {
                continue;
            }

            $identifierType = $this->typeResolver->resolveIdentifierType(
                $record['relatedIdentifierType'] ?? $record['identifierType'] ?? $record['identifier_type'] ?? null,
            );
            $relationType = $this->typeResolver->resolveRelationType(
                $record['relationType'] ?? $record['relation_type'] ?? null,
            );

            if ($identifierType === null || $relationType === null) {
                continue;
            }

            $identifier = $record['relatedIdentifier'] ?? $record['identifier'] ?? '';
            $identifier = is_string($identifier) ? trim($identifier) : '';

            if ($identifier === '' && ! $keepIncomplete) {
                continue;
            }

            $item = [
                'relatedIdentifier' => $identifier,
                'relatedIdentifierType' => $identifierType,
                'relationType' => $relationType,
                '__position' => $position,
            ];

            foreach (self::OPTIONAL_FIELDS as $field) {
                $value = $this->optionalValue($record, $field);

                if ($value !== null) {
                    $item[$field] = $value;
                }
            }

            $canonical[] = $item;
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function optionalValue(array $record, string $field): ?string
    {
        $aliases = match ($field) {
            'resourceTypeGeneral' => ['resourceTypeGeneral', 'resource_type_general'],
            'relationTypeInformation' => ['relationTypeInformation', 'relation_type_information'],
            'relatedMetadataScheme' => ['relatedMetadataScheme', 'related_metadata_scheme'],
            'schemeUri' => ['schemeUri', 'schemeURI', 'scheme_uri'],
            'schemeType' => ['schemeType', 'scheme_type'],
            'citationLabel' => ['citationLabel', 'citation_label'],
            default => [],
        };

        foreach ($aliases as $alias) {
            if (! isset($record[$alias]) || ! is_scalar($record[$alias])) {
                continue;
            }

            $value = trim((string) $record[$alias]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|int>  $incomplete
     * @param  list<array<string, string|int>>  $xml
     * @param  array<int, true>  $usedXmlIndexes
     */
    private function matchingXmlIndex(array $incomplete, array $xml, array $usedXmlIndexes): ?int
    {
        $position = (int) $incomplete['__position'];

        if (
            ! isset($usedXmlIndexes[$position])
            && isset($xml[$position])
            && $this->sameTypes($incomplete, $xml[$position])
        ) {
            return $position;
        }

        $matches = [];

        foreach ($xml as $index => $candidate) {
            if (! isset($usedXmlIndexes[$index]) && $this->sameTypes($incomplete, $candidate)) {
                $matches[] = $index;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  array<string, string|int>  $first
     * @param  array<string, string|int>  $second
     */
    private function sameTypes(array $first, array $second): bool
    {
        return $first['relatedIdentifierType'] === $second['relatedIdentifierType']
            && $first['relationType'] === $second['relationType'];
    }

    /**
     * @param  array<string, string|int>  $preferred
     * @param  array<string, string|int>  $fallback
     * @return array<string, string|int>
     */
    private function supplement(array $preferred, array $fallback): array
    {
        if ($this->identifier($preferred) === '') {
            $preferred['relatedIdentifier'] = $this->identifier($fallback);
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            if ($this->valueIsBlank($preferred[$field] ?? null) && ! $this->valueIsBlank($fallback[$field] ?? null)) {
                $preferred[$field] = $fallback[$field];
            }
        }

        return $preferred;
    }

    /**
     * @param  list<array<string, string>>  $merged
     * @param  array<string, int>  $keyIndexes
     * @param  array<string, string|int>  $record
     */
    private function appendOrSupplement(array &$merged, array &$keyIndexes, array $record): void
    {
        $identifier = $this->identifier($record);

        if ($identifier === '') {
            return;
        }

        $key = $record['relatedIdentifierType'].'|'
            .$this->normalizedIdentifier($identifier, (string) $record['relatedIdentifierType']).'|'
            .$record['relationType'];

        if (isset($keyIndexes[$key])) {
            $existingIndex = $keyIndexes[$key];
            /** @var array<string, string|int> $existing */
            $existing = $merged[$existingIndex];
            $merged[$existingIndex] = $this->withoutInternalFields($this->supplement($existing, $record));

            return;
        }

        $keyIndexes[$key] = count($merged);
        $merged[] = $this->withoutInternalFields($record);
    }

    /**
     * @param  array<string, string|int>  $record
     */
    private function identifier(array $record): string
    {
        return trim((string) ($record['relatedIdentifier'] ?? ''));
    }

    private function normalizedIdentifier(string $identifier, string $identifierType): string
    {
        $identifier = trim($identifier);

        if ($identifierType === 'DOI') {
            $identifier = preg_replace(
                '/^(?:doi:\s*|https?:\/\/(?:doi\.org|dx\.doi\.org)\/)/i',
                '',
                $identifier,
            ) ?? $identifier;
        }

        return mb_strtolower(trim($identifier));
    }

    private function valueIsBlank(mixed $value): bool
    {
        return ! is_scalar($value) || trim((string) $value) === '';
    }

    /**
     * @param  array<string, string|int>  $record
     * @return array<string, string>
     */
    private function withoutInternalFields(array $record): array
    {
        unset($record['__position']);

        /** @var array<string, string> $record */
        return $record;
    }
}
