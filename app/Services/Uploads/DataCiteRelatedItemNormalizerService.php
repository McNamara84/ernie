<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use Illuminate\Support\Str;

/**
 * Converts validated DataCite API JSON (including converted JSON-LD) to the
 * same editor/storage payload produced by the XML related-item parser.
 */
final class DataCiteRelatedItemNormalizerService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalize(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $position => $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $this->string($item['relatedItemType'] ?? null);
            $relation = $this->string($item['relationType'] ?? null);
            $titles = $this->titles($item['titles'] ?? []);
            if ($type === null || $relation === null || $titles === []) {
                continue;
            }

            $entry = [
                'related_item_type' => Str::studly($type),
                'relation_type_slug' => $relation,
                'titles' => $titles,
                'creators' => $this->people($item['creators'] ?? []),
                'contributors' => $this->people($item['contributors'] ?? [], true),
                'publication_year' => is_numeric($item['publicationYear'] ?? null) ? (int) $item['publicationYear'] : null,
                'volume' => $this->string($item['volume'] ?? null),
                'issue' => $this->string($item['issue'] ?? null),
                'first_page' => $this->string($item['firstPage'] ?? null),
                'last_page' => $this->string($item['lastPage'] ?? null),
                'publisher' => $this->string($item['publisher'] ?? null),
                'edition' => $this->string($item['edition'] ?? null),
                'position' => $position,
            ];

            if (array_key_exists('relationTypeInformation', $item)) {
                $entry['relation_type_information'] = $this->string($item['relationTypeInformation']);
            }

            if (($number = $this->string($item['number'] ?? null)) !== null) {
                $entry['number'] = $number;
                $entry['number_type'] = $this->string($item['numberType'] ?? null);
            }

            $identifier = $item['relatedItemIdentifier'] ?? null;
            $identifierData = is_array($identifier) ? $identifier : [];
            $identifierValue = $this->string(is_array($identifier) ? ($identifier['relatedItemIdentifier'] ?? null) : $identifier);
            if ($identifierValue !== null) {
                $entry['identifier'] = $identifierValue;
                $entry['identifier_type'] = $this->string($identifierData['relatedItemIdentifierType'] ?? $item['relatedItemIdentifierType'] ?? null);
                $entry['related_metadata_scheme'] = $this->string($identifierData['relatedMetadataScheme'] ?? null);
                $entry['scheme_uri'] = $this->string($identifierData['schemeUri'] ?? null);
                $entry['scheme_type'] = $this->string($identifierData['schemeType'] ?? null);
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @return array<int, array{title: string, title_type: string, language: string|null}>
     */
    private function titles(mixed $titles): array
    {
        if (! is_array($titles)) {
            return [];
        }

        $result = [];
        foreach ($titles as $title) {
            if (! is_array($title) || ($value = $this->string($title['title'] ?? null)) === null) {
                continue;
            }
            $result[] = [
                'title' => $value,
                'title_type' => $this->string($title['titleType'] ?? null) ?? 'MainTitle',
                'language' => $this->string($title['lang'] ?? null),
            ];
        }
        if ($result !== [] && ! in_array('MainTitle', array_column($result, 'title_type'), true)) {
            $result[0]['title_type'] = 'MainTitle';
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function people(mixed $people, bool $contributors = false): array
    {
        if (! is_array($people)) {
            return [];
        }

        $result = [];
        foreach ($people as $person) {
            if (! is_array($person) || ($name = $this->string($person['name'] ?? null)) === null) {
                continue;
            }

            $entry = [
                'name_type' => $this->string($person['nameType'] ?? null) ?? 'Personal',
                'name' => $name,
                'given_name' => $this->string($person['givenName'] ?? null),
                'family_name' => $this->string($person['familyName'] ?? null),
            ];
            if ($contributors) {
                $entry['contributor_type'] = $this->string($person['contributorType'] ?? null) ?? 'Other';
            }

            $identifiers = $person['nameIdentifiers'] ?? [];
            $firstIdentifier = is_array($identifiers) ? ($identifiers[0] ?? null) : null;
            if (is_array($firstIdentifier) && ($value = $this->string($firstIdentifier['nameIdentifier'] ?? null)) !== null) {
                $entry['name_identifier'] = $value;
                $entry['name_identifier_scheme'] = $this->string($firstIdentifier['nameIdentifierScheme'] ?? null);
                $entry['scheme_uri'] = $this->string($firstIdentifier['schemeUri'] ?? null);
            }

            $affiliations = [];
            foreach (is_array($person['affiliation'] ?? null) ? $person['affiliation'] : [] as $affiliation) {
                if (! is_array($affiliation) || ($affiliationName = $this->string($affiliation['name'] ?? null)) === null) {
                    continue;
                }
                $affiliations[] = [
                    'name' => $affiliationName,
                    'affiliation_identifier' => $this->string($affiliation['affiliationIdentifier'] ?? null),
                    'scheme' => $this->string($affiliation['affiliationIdentifierScheme'] ?? null),
                    'scheme_uri' => $this->string($affiliation['schemeUri'] ?? null),
                ];
            }
            if ($affiliations !== []) {
                $entry['affiliations'] = $affiliations;
            }
            $result[] = $entry;
        }

        return $result;
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
