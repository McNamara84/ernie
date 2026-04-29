<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Models\ResourceType;
use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses the top-level resource identifier fields (DOI, year, version, language)
 * and the resource type from a DataCite XML document.
 */
final readonly class IdentifierSectionParser
{
    /**
     * @return array{
     *     doi: string|null,
     *     year: string|null,
     *     version: string|null,
     *     language: string|null,
     *     resourceType: string|null,
     * }
     */
    public function parse(XmlReader $reader): array
    {
        $doi = XmlElementHelpers::firstStringFromQuery(
            $reader->xpathValue('//*[local-name()="identifier" and @identifierType="DOI"]'),
        );
        $year = XmlElementHelpers::firstStringFromQuery(
            $reader->xpathValue('//*[local-name()="publicationYear"]'),
        );
        $version = XmlElementHelpers::firstStringFromQuery(
            $reader->xpathValue('//*[local-name()="version"]'),
        );
        $language = XmlElementHelpers::firstStringFromQuery(
            $reader->xpathValue('//*[local-name()="language"]'),
        );

        $resourceTypeElement = XmlElementHelpers::firstElementFromQuery(
            $reader->xpathElement('//*[local-name()="resourceType"]'),
        );
        $resourceTypeName = $resourceTypeElement?->getAttribute('resourceTypeGeneral');
        $resourceType = null;

        if ($resourceTypeName !== null) {
            // The XML attribute is the DataCite `resourceTypeGeneral` enum
            // (PascalCase, no spaces — e.g. `PhysicalObject`, `JournalArticle`).
            // The seeded `resource_types.name` column is the human-readable
            // form ("Physical Object", "Journal Article"), so a direct
            // `LOWER(name) = LOWER('PhysicalObject')` comparison would never
            // match. Resolve the lookup in PHP via
            // `ResourceType::nameToDataciteResourceTypeGeneral()` so the same
            // mapping that `RelatedItemSectionParser` and the vocabularies
            // endpoint use is applied here, too.
            $needle = Str::lower($resourceTypeName);

            $resourceTypeModel = ResourceType::query()
                ->get(['id', 'name'])
                ->first(fn (ResourceType $type): bool => Str::lower(
                    ResourceType::nameToDataciteResourceTypeGeneral($type->name)
                ) === $needle);

            $resourceType = $resourceTypeModel?->id !== null ? (string) $resourceTypeModel->id : null;
        }

        return [
            'doi' => $doi,
            'year' => $year,
            'version' => $version,
            'language' => $language,
            'resourceType' => $resourceType,
        ];
    }
}
