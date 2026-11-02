<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Services\Imports\ContactPersonImportService;
use App\Services\Xml\Sections\AuthorSectionParser;
use App\Services\Xml\Sections\ContributorSectionParser;
use App\Services\Xml\Sections\CoverageSectionParser;
use App\Services\Xml\Sections\DateSectionParser;
use App\Services\Xml\Sections\DescriptionSectionParser;
use App\Services\Xml\Sections\FundingReferenceSectionParser;
use App\Services\Xml\Sections\IdentifierSectionParser;
use App\Services\Xml\Sections\IsoContactSectionParser;
use App\Services\Xml\Sections\RelatedItemSectionParser;
use App\Services\Xml\Sections\RelatedWorkAndInstrumentSectionParser;
use App\Services\Xml\Sections\RightsSectionParser;
use App\Services\Xml\Sections\SubjectSectionParser;
use App\Services\Xml\Sections\TitleSectionParser;
use Saloon\XmlWrangler\XmlReader;

/**
 * Orchestrator: parses a DataCite XML document into a {@see DataCiteXmlImportResult}
 * by delegating to the section parsers and wiring up cross-section logic
 * (date filtering, contact-person → author merging).
 */
final readonly class DataCiteXmlImportParser
{
    public function __construct(
        private ContactPersonImportService $contactPersonResolver,
        private IdentifierSectionParser $identifierParser,
        private TitleSectionParser $titleParser,
        private RightsSectionParser $rightsParser,
        private AuthorSectionParser $authorParser,
        private ContributorSectionParser $contributorParser,
        private IsoContactSectionParser $isoContactParser,
        private DescriptionSectionParser $descriptionParser,
        private DateSectionParser $dateParser,
        private CoverageSectionParser $coverageParser,
        private SubjectSectionParser $subjectParser,
        private FundingReferenceSectionParser $fundingReferenceParser,
        private RelatedItemSectionParser $relatedItemParser,
        private RelatedWorkAndInstrumentSectionParser $relatedWorkAndInstrumentParser,
    ) {}

    public function parse(XmlReader $reader, string $filename, ?string $xmlContents = null): DataCiteXmlImportResult
    {
        $identifierData = $this->identifierParser->parse($reader);
        $resourceType = $identifierData['resourceType'];

        $authors = $this->authorParser->parse($reader);
        $contributorsAndLabs = $this->contributorParser->parse($reader);
        $contributors = $contributorsAndLabs['contributors'];
        $mslLaboratories = $contributorsAndLabs['mslLaboratories'];

        $isoContactInfo = $this->isoContactParser->parse($reader);
        ['authors' => $authors, 'contributors' => $contributors] =
            $this->contactPersonResolver->resolve($authors, $contributors, $isoContactInfo);

        $descriptions = $this->descriptionParser->parse($reader, $xmlContents);
        $dates = $this->dateParser->parse($reader);
        $coverages = $this->coverageParser->parse($reader, $dates);

        // Remove Coverage dates from dates array (they are now in coverages)
        // and strip the rawValue key used only for coverage time extraction.
        $dates = array_values(array_map(
            fn (array $date) => array_diff_key($date, ['rawValue' => true]),
            array_filter(
                $dates,
                fn (array $date) => $date['dateType'] !== 'coverage',
            ),
        ));

        $subjects = $this->subjectParser->parse($reader);

        $rawRights = $this->rightsParser->parseRawRights($reader);
        $licenses = array_values(array_filter(array_map(
            fn (array $rights): ?string => isset($rights['rightsIdentifier']) ? (string) $rights['rightsIdentifier'] : null,
            $rawRights,
        )));
        $titles = $this->titleParser->parse($reader);

        ['relatedWorks' => $relatedWorks, 'instruments' => $instruments] =
            $this->relatedWorkAndInstrumentParser->parse($reader, $filename);

        $fundingReferences = $this->fundingReferenceParser->parse($reader);
        $relatedItems = $this->relatedItemParser->parse($reader);

        return new DataCiteXmlImportResult(
            doi: $identifierData['doi'],
            year: $identifierData['year'],
            version: $identifierData['version'],
            language: $identifierData['language'],
            resourceType: $resourceType !== null ? (string) $resourceType : null,
            titles: $titles,
            licenses: $licenses,
            rawRights: $rawRights,
            authors: $authors,
            contributors: $contributors,
            descriptions: $descriptions,
            dates: $dates,
            coverages: $coverages,
            relatedWorks: $relatedWorks,
            instruments: $instruments,
            controlledKeywords: $subjects->controlledKeywords,
            freeKeywords: $subjects->freeKeywords,
            fundingReferences: $fundingReferences,
            mslLaboratories: $mslLaboratories,
            relatedItems: $relatedItems,
        );
    }
}
