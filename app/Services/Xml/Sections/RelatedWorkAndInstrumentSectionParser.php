<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\Xml\OriginalDataCiteRelatedIdentifierExtractionService;
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
        private OriginalDataCiteRelatedIdentifierExtractionService $relatedIdentifierExtractor,
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
        $relatedIdentifiers = $this->relatedIdentifierExtractor->extractFromReader($reader, $filename);

        $relatedWorks = [];
        $instruments = [];
        $citationResolutionDeadline = microtime(true) + RelatedIdentifierCitationLabelService::DEFAULT_AGGREGATE_TIMEOUT_SECONDS;

        foreach ($relatedIdentifiers as $relatedIdentifier) {
            $identifier = $relatedIdentifier['relatedIdentifier'];
            $identifierType = $relatedIdentifier['relatedIdentifierType'];
            $relationType = $relatedIdentifier['relationType'];

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
                'relation_type_information' => $relatedIdentifier['relationTypeInformation'] ?? null,
                'citation_label' => $this->citationLabelService->resolveBestEffort($identifier, $identifierType, $citationResolutionDeadline),
                'position' => count($relatedWorks),
            ];
        }

        return [
            'relatedWorks' => $relatedWorks,
            'instruments' => $instruments,
        ];
    }
}
