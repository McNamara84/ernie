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
            // Match it against `resource_types.slug`, which is the immutable
            // canonical key (kebab-case, e.g. `physical-object`,
            // `journal-article`) — `name` is editable via editor settings and
            // therefore unsafe as the lookup key. The same PascalCase
            // representation is produced by
            // `ResourceType::slugToDataciteResourceTypeGeneral()`, which is
            // also used by `RelatedItemSectionParser` and the vocabularies
            // endpoint.
            $needle = Str::lower($resourceTypeName);

            $resourceTypeModel = ResourceType::query()
                ->get(['id', 'slug'])
                ->first(fn (ResourceType $type): bool => Str::lower(
                    ResourceType::slugToDataciteResourceTypeGeneral($type->slug)
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
