<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<relatedItems>/<relatedItem>` (DataCite 4.7) into the form payload
 * accepted by `POST /resources/{resource}/related-items`.
 */
final readonly class RelatedItemSectionParser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(XmlReader $reader): array
    {
        $items = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="relatedItems"]/*[local-name()="relatedItem"]')
            ->get();

        $result = [];

        foreach ($items as $position => $item) {
            $relatedItemType = $item->getAttribute('relatedItemType');
            $relationTypeSlug = $item->getAttribute('relationType');

            if (! is_string($relatedItemType) || trim($relatedItemType) === '') {
                continue;
            }
            if (! is_string($relationTypeSlug) || trim($relationTypeSlug) === '') {
                continue;
            }

            $titles = $this->extractTitles($item);
            if ($titles === []) {
                continue;
            }

            // DataCite XML carries the resource type general value in PascalCase
            // (e.g. "JournalArticle"), but `resource_types.slug` is generated
            // from `Str::slug($name)` in `ResourceTypeSeeder`, which yields
            // kebab-case ("journal-article"). `StoreResourceRequest` validates
            // the parser output via `Rule::exists('resource_types', 'slug')`,
            // so without normalisation imported related items would fail to
            // round-trip through save. `Str::kebab` is idempotent for values
            // that are already kebab-case, and `Str::slug` cannot be used here
            // because it does not split CamelCase ("JournalArticle" →
            // "journalarticle", which still does not match the seeded slug).
            $entry = [
                'related_item_type' => Str::kebab(trim($relatedItemType)),
                'relation_type_slug' => trim($relationTypeSlug),
                'titles' => $titles,
                'creators' => $this->extractCreators($item),
                'contributors' => $this->extractContributors($item),
                'publication_year' => XmlElementHelpers::intOrNull(XmlElementHelpers::scalarChild($item, 'publicationYear')),
                'volume' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($item, 'volume')),
                'issue' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($item, 'issue')),
                'first_page' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($item, 'firstPage')),
                'last_page' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($item, 'lastPage')),
                'publisher' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($item, 'publisher')),
                'edition' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($item, 'edition')),
                'position' => $position,
            ];

            $numberElement = XmlElementHelpers::firstChildElement($item, 'number');
            if ($numberElement !== null) {
                $numberValue = XmlElementHelpers::stringValue($numberElement);
                if (is_string($numberValue) && trim($numberValue) !== '') {
                    $entry['number'] = trim($numberValue);
                    $typeAttr = $numberElement->getAttribute('numberType');
                    $entry['number_type'] = is_string($typeAttr) && trim($typeAttr) !== '' ? trim($typeAttr) : null;
                }
            }

            $identifierElement = XmlElementHelpers::firstChildElement($item, 'relatedItemIdentifier');
            if ($identifierElement !== null) {
                $identifierValue = XmlElementHelpers::stringValue($identifierElement);
                if (is_string($identifierValue) && trim($identifierValue) !== '') {
                    $entry['identifier'] = trim($identifierValue);
                    $idType = $identifierElement->getAttribute('relatedItemIdentifierType');
                    $entry['identifier_type'] = is_string($idType) && trim($idType) !== '' ? trim($idType) : null;
                    $scheme = $identifierElement->getAttribute('relatedMetadataScheme');
                    if (is_string($scheme) && trim($scheme) !== '') {
                        $entry['related_metadata_scheme'] = trim($scheme);
                    }
                    $schemeUri = $identifierElement->getAttribute('schemeURI');
                    if (is_string($schemeUri) && trim($schemeUri) !== '') {
                        $entry['scheme_uri'] = trim($schemeUri);
                    }
                    $schemeType = $identifierElement->getAttribute('schemeType');
                    if (is_string($schemeType) && trim($schemeType) !== '') {
                        $entry['scheme_type'] = trim($schemeType);
                    }
                }
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @return array<int, array{title: string, title_type: string}>
     */
    private function extractTitles(Element $item): array
    {
        $titles = [];
        foreach (XmlElementHelpers::childElements($item, 'titles') as $titlesWrapper) {
            foreach (XmlElementHelpers::childElements($titlesWrapper, 'title') as $t) {
                $value = XmlElementHelpers::stringValue($t);
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $typeAttr = $t->getAttribute('titleType');
                $titleType = is_string($typeAttr) && trim($typeAttr) !== '' ? trim($typeAttr) : 'MainTitle';
                $titles[] = [
                    'title' => trim($value),
                    'title_type' => $titleType,
                ];
            }
        }

        $hasMain = false;
        foreach ($titles as $t) {
            if ($t['title_type'] === 'MainTitle') {
                $hasMain = true;

                break;
            }
        }
        if (! $hasMain && $titles !== []) {
            $titles[0]['title_type'] = 'MainTitle';
        }

        return $titles;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCreators(Element $item): array
    {
        $result = [];
        foreach (XmlElementHelpers::childElements($item, 'creators') as $creatorsEl) {
            foreach (XmlElementHelpers::childElements($creatorsEl, 'creator') as $creator) {
                $entry = $this->extractPerson($creator, 'creatorName');
                if ($entry !== null) {
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractContributors(Element $item): array
    {
        $result = [];
        foreach (XmlElementHelpers::childElements($item, 'contributors') as $contributorsEl) {
            foreach (XmlElementHelpers::childElements($contributorsEl, 'contributor') as $contributor) {
                $entry = $this->extractPerson($contributor, 'contributorName');
                if ($entry === null) {
                    continue;
                }
                $type = $contributor->getAttribute('contributorType');
                if (is_string($type) && trim($type) !== '') {
                    $entry['contributor_type'] = trim($type);
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPerson(Element $person, string $nameElementName): ?array
    {
        $nameElement = XmlElementHelpers::firstChildElement($person, $nameElementName);
        if ($nameElement === null) {
            return null;
        }

        $nameValue = XmlElementHelpers::stringValue($nameElement);
        if (! is_string($nameValue) || trim($nameValue) === '') {
            return null;
        }

        $nameTypeAttr = $nameElement->getAttribute('nameType');
        $nameType = is_string($nameTypeAttr) && trim($nameTypeAttr) !== '' ? trim($nameTypeAttr) : 'Personal';

        $entry = [
            'name_type' => $nameType,
            'name' => trim($nameValue),
            'given_name' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($person, 'givenName')),
            'family_name' => XmlElementHelpers::stringOrNull(XmlElementHelpers::scalarChild($person, 'familyName')),
        ];

        $idElement = XmlElementHelpers::firstChildElement($person, 'nameIdentifier');
        if ($idElement !== null) {
            $idValue = XmlElementHelpers::stringValue($idElement);
            if (is_string($idValue) && trim($idValue) !== '') {
                $entry['name_identifier'] = trim($idValue);
                $scheme = $idElement->getAttribute('nameIdentifierScheme');
                if (is_string($scheme) && trim($scheme) !== '') {
                    $entry['name_identifier_scheme'] = trim($scheme);
                }
            }
        }

        $affiliations = [];
        foreach (XmlElementHelpers::childElements($person, 'affiliation') as $aff) {
            $affValue = XmlElementHelpers::stringValue($aff);
            if (! is_string($affValue) || trim($affValue) === '') {
                continue;
            }
            $affEntry = ['name' => trim($affValue)];
            $affId = $aff->getAttribute('affiliationIdentifier');
            if (is_string($affId) && trim($affId) !== '') {
                $affEntry['affiliation_identifier'] = trim($affId);
            }
            $affScheme = $aff->getAttribute('affiliationIdentifierScheme');
            if (is_string($affScheme) && trim($affScheme) !== '') {
                $affEntry['scheme'] = trim($affScheme);
            }
            $affiliations[] = $affEntry;
        }
        if ($affiliations !== []) {
            $entry['affiliations'] = $affiliations;
        }

        return $entry;
    }
}
