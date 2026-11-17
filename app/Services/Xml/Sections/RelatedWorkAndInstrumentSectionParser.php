<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\InstrumentNameResolutionService;
use App\Services\InstrumentReferenceClassifierService;
use App\Services\Xml\OriginalDataCiteRelatedIdentifierExtractionService;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<relatedIdentifiers>/<relatedIdentifier>` from a DataCite XML
 * document and separates legacy Handles and explicitly typed or locally known
 * instrument DOIs with relationType="IsCollectedBy" into the editor's
 * "Used Instruments" form section.
 */
final readonly class RelatedWorkAndInstrumentSectionParser
{
    public function __construct(
        private OriginalDataCiteRelatedIdentifierExtractionService $relatedIdentifierExtractor,
        private RelatedIdentifierCitationLabelService $citationLabelService,
        private InstrumentNameResolutionService $instrumentResolver,
        private InstrumentReferenceClassifierService $instrumentClassifier,
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

        $instrumentReferences = $this->instrumentClassifier->classify($relatedIdentifiers);
        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            $identifier = $relatedIdentifier['relatedIdentifier'];
            $identifierType = $relatedIdentifier['relatedIdentifierType'];
            $relationType = $relatedIdentifier['relationType'];

            if ($instrumentReferences[$index]) {
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
            'instruments' => $this->instrumentResolver->resolve($instruments),
        ];
    }
}
