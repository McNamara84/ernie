<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\RelatedIdentifierTypeResolverService;
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
    public function __construct(
        private RelatedIdentifierTypeResolverService $typeResolver,
        private RelatedIdentifierCitationLabelService $citationLabelService,
    ) {}

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

            $identifierType = $this->typeResolver->resolveIdentifierType($identifierTypeRaw);
            $relationType = $this->typeResolver->resolveRelationType($relationTypeRaw);

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
                'citation_label' => $this->citationLabelService->resolve($identifier, $identifierType),
                'position' => count($relatedWorks),
            ];
        }

        return [
            'relatedWorks' => $relatedWorks,
            'instruments' => $instruments,
        ];
    }
}
