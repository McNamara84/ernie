<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Facades\Log;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<relatedIdentifiers>/<relatedIdentifier>` from a DataCite XML
 * document and separates instrument PIDs (relationType="IsCollectedBy" with
 * identifierType="Handle") into their own array used by the editor's
 * "Used Instruments" form section.
 */
final readonly class RelatedWorkAndInstrumentSectionParser
{
    /**
     * Canonical DataCite related identifier types supported by ERNIE editor.
     *
     * @var string[]
     */
    private const RELATED_IDENTIFIER_TYPES = [
        'DOI', 'URL', 'Handle', 'IGSN', 'URN', 'ISBN', 'ISSN', 'PURL', 'ARK',
        'arXiv', 'bibcode', 'CSTR', 'EAN13', 'EISSN', 'ISTC', 'LISSN', 'LSID',
        'PMID', 'RAiD', 'RRID', 'SWHID', 'UPC', 'w3id',
    ];

    /**
     * Canonical DataCite relation types supported by ERNIE editor.
     *
     * @var string[]
     */
    private const RELATED_RELATION_TYPES = [
        'Cites', 'IsCitedBy', 'References', 'IsReferencedBy',
        'Documents', 'IsDocumentedBy', 'Describes', 'IsDescribedBy',
        'IsNewVersionOf', 'IsPreviousVersionOf', 'HasVersion', 'IsVersionOf',
        'HasTranslation', 'IsTranslationOf',
        'Continues', 'IsContinuedBy', 'Obsoletes', 'IsObsoletedBy',
        'IsVariantFormOf', 'IsOriginalFormOf', 'IsIdenticalTo',
        'HasPart', 'IsPartOf', 'Compiles', 'IsCompiledBy',
        'IsSourceOf', 'IsDerivedFrom',
        'IsSupplementTo', 'IsSupplementedBy',
        'Requires', 'IsRequiredBy',
        'HasMetadata', 'IsMetadataFor',
        'Reviews', 'IsReviewedBy',
        'IsPublishedIn', 'Collects', 'IsCollectedBy',
        'Other',
    ];

    /**
     * @return array{
     *     relatedWorks: array<int, array{identifier: string, identifier_type: string, relation_type: string, relation_type_information: string|null, position: int}>,
     *     instruments: array<int, array{pid: string, pidType: string, name: string}>,
     * }
     */
    public function parse(XmlReader $reader, string $filename): array
    {
        $relatedIdentifierElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="relatedIdentifiers"]/*[local-name()="relatedIdentifier"]')
            ->get();

        /** @var array<string, string> $identifierTypeLookup */
        $identifierTypeLookup = [];
        foreach (self::RELATED_IDENTIFIER_TYPES as $name) {
            $identifierTypeLookup[mb_strtolower($name)] = $name;
        }

        /** @var array<string, string> $relationTypeLookup */
        $relationTypeLookup = [];
        foreach (self::RELATED_RELATION_TYPES as $name) {
            $relationTypeLookup[mb_strtolower($name)] = $name;
        }

        $relatedWorks = [];
        $instruments = [];

        foreach ($relatedIdentifierElements as $index => $element) {
            $identifier = XmlElementHelpers::stringValue($element);
            $identifierTypeRaw = $element->getAttribute('relatedIdentifierType');
            $relationTypeRaw = $element->getAttribute('relationType');
            $relationTypeInformationRaw = $element->getAttribute('relationTypeInformation');

            if (! is_string($identifier) || $identifier === '') {
                continue;
            }

            $identifierType = is_string($identifierTypeRaw)
                ? ($identifierTypeLookup[mb_strtolower(trim($identifierTypeRaw))] ?? null)
                : null;
            $relationType = is_string($relationTypeRaw)
                ? ($relationTypeLookup[mb_strtolower(trim($relationTypeRaw))] ?? null)
                : null;

            if ($identifierType === null || $relationType === null) {
                Log::warning('Skipping related identifier with unsupported type values during XML upload', [
                    'filename' => $filename,
                    'index' => $index,
                    'identifier' => $identifier,
                    'relatedIdentifierType' => $identifierTypeRaw,
                    'relationType' => $relationTypeRaw,
                ]);

                continue;
            }

            if ($relationType === 'IsCollectedBy' && $identifierType === 'Handle') {
                $instruments[] = [
                    'pid' => $identifier,
                    'pidType' => $identifierType,
                    'name' => $identifier,
                ];

                continue;
            }

            $relatedWorks[] = [
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'relation_type' => $relationType,
                'relation_type_information' => is_string($relationTypeInformationRaw) && trim($relationTypeInformationRaw) !== '' ? trim($relationTypeInformationRaw) : null,
                'position' => count($relatedWorks),
            ];
        }

        return [
            'relatedWorks' => $relatedWorks,
            'instruments' => $instruments,
        ];
    }
}
