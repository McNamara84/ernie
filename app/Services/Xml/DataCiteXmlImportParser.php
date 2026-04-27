<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Services\Xml\Sections\AuthorSectionParser;
use App\Services\Xml\Sections\ContributorSectionParser;
use App\Services\Xml\Sections\CoverageSectionParser;
use App\Services\Xml\Sections\DateSectionParser;
use App\Services\Xml\Sections\DescriptionSectionParser;
use App\Services\Xml\Sections\FundingReferenceSectionParser;
use App\Services\Xml\Sections\GcmdKeywordSectionParser;
use App\Services\Xml\Sections\IdentifierSectionParser;
use App\Services\Xml\Sections\IsoContactSectionParser;
use App\Services\Xml\Sections\RelatedItemSectionParser;
use App\Services\Xml\Sections\RelatedWorkAndInstrumentSectionParser;
use App\Services\Xml\Sections\RightsSectionParser;
use App\Services\Xml\Sections\TitleSectionParser;
use App\Support\XmlKeywordExtractor;
use Saloon\XmlWrangler\XmlReader;

/**
 * Orchestrator: parses a DataCite XML document into a {@see DataCiteXmlImportResult}
 * by delegating to the section parsers and wiring up cross-section logic
 * (date filtering, contact-person → author merging).
 */
final readonly class DataCiteXmlImportParser
{
    public function __construct(
        private IdentifierSectionParser $identifierParser,
        private TitleSectionParser $titleParser,
        private RightsSectionParser $rightsParser,
        private AuthorSectionParser $authorParser,
        private ContributorSectionParser $contributorParser,
        private IsoContactSectionParser $isoContactParser,
        private DescriptionSectionParser $descriptionParser,
        private DateSectionParser $dateParser,
        private CoverageSectionParser $coverageParser,
        private GcmdKeywordSectionParser $gcmdKeywordParser,
        private FundingReferenceSectionParser $fundingReferenceParser,
        private RelatedItemSectionParser $relatedItemParser,
        private RelatedWorkAndInstrumentSectionParser $relatedWorkAndInstrumentParser,
        private XmlKeywordExtractor $keywordExtractor,
    ) {}

    public function parse(XmlReader $reader, string $filename): DataCiteXmlImportResult
    {
        $identifierData = $this->identifierParser->parse($reader);
        $resourceType = $identifierData['resourceType'];

        $authors = $this->authorParser->parse($reader);
        $contributorsAndLabs = $this->contributorParser->parse($reader);
        $contributors = $contributorsAndLabs['contributors'];
        $mslLaboratories = $contributorsAndLabs['mslLaboratories'];
        $contactPersons = $contributorsAndLabs['contactPersons'];

        $isoContactInfo = $this->isoContactParser->parse($reader);
        $authors = $this->mergeContactPersonsIntoAuthors($authors, $contactPersons, $isoContactInfo);

        $descriptions = $this->descriptionParser->parse($reader);
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

        $gcmdKeywords = $this->gcmdKeywordParser->parse($reader);
        $freeKeywords = $this->keywordExtractor->extractFreeKeywords($reader);
        $mslKeywords = $this->keywordExtractor->extractMslKeywords($reader);
        $gemetKeywords = $this->keywordExtractor->extractGemetKeywords($reader);

        $licenses = $this->rightsParser->parse($reader);
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
            authors: $authors,
            contributors: $contributors,
            descriptions: $descriptions,
            dates: $dates,
            coverages: $coverages,
            relatedWorks: $relatedWorks,
            instruments: $instruments,
            gcmdKeywords: $gcmdKeywords,
            freeKeywords: $freeKeywords,
            mslKeywords: $mslKeywords,
            gemetKeywords: $gemetKeywords,
            fundingReferences: $fundingReferences,
            mslLaboratories: $mslLaboratories,
            relatedItems: $relatedItems,
        );
    }

    /**
     * Merge ContactPerson contributors into authors with isContact flag and contact info.
     *
     * Matching priority:
     * 1. ORCID (if both have it)
     * 2. Name (familyName + givenName, case-insensitive)
     *
     * @param  array<int, array<string, mixed>>  $authors
     * @param  array<int, array<string, mixed>>  $contactPersons
     * @param  array<string, array{email: string, website: string}>  $isoContactInfo
     * @return array<int, array<string, mixed>>
     */
    private function mergeContactPersonsIntoAuthors(array $authors, array $contactPersons, array $isoContactInfo): array
    {
        foreach ($contactPersons as $cp) {
            if (($cp['type'] ?? '') === 'institution') {
                continue;
            }

            $matched = false;

            $cpOrcid = $cp['orcid'] ?? '';
            if ($cpOrcid !== '') {
                foreach ($authors as &$author) {
                    if (($author['type'] ?? '') === 'person' && ($author['orcid'] ?? '') === $cpOrcid) {
                        $author['isContact'] = true;
                        $this->enrichAuthorWithContactInfo($author, $isoContactInfo);
                        $matched = true;
                        break;
                    }
                }
                unset($author);
            }

            if (! $matched) {
                $cpLastName = trim($cp['lastName'] ?? '');
                $cpFirstName = trim($cp['firstName'] ?? '');

                if ($cpLastName === '') {
                    continue;
                }

                $cpNameKey = self::buildNameKey($cpLastName, $cpFirstName);

                foreach ($authors as &$author) {
                    if (($author['type'] ?? '') === 'person') {
                        $authorNameKey = self::buildNameKey(
                            (string) ($author['lastName'] ?? ''),
                            (string) ($author['firstName'] ?? ''),
                        );
                        if ($cpNameKey === $authorNameKey) {
                            $author['isContact'] = true;
                            $this->enrichAuthorWithContactInfo($author, $isoContactInfo);
                            $matched = true;
                            break;
                        }
                    }
                }
                unset($author);
            }

            if (! $matched) {
                $newAuthor = [
                    'type' => 'person',
                    'orcid' => $cp['orcid'] ?? '',
                    'firstName' => $cp['firstName'] ?? '',
                    'lastName' => $cp['lastName'] ?? '',
                    'affiliations' => $cp['affiliations'] ?? [],
                    'isContact' => true,
                    'email' => '',
                    'website' => '',
                ];
                $this->enrichAuthorWithContactInfo($newAuthor, $isoContactInfo);
                $authors[] = $newAuthor;
            }
        }

        return $authors;
    }

    /**
     * @param  array<string, mixed>  $author
     * @param  array<string, array{email: string, website: string}>  $isoContactInfo
     */
    private function enrichAuthorWithContactInfo(array &$author, array $isoContactInfo): void
    {
        $nameKey = self::buildNameKey(
            (string) ($author['lastName'] ?? ''),
            (string) ($author['firstName'] ?? ''),
        );

        if (isset($isoContactInfo[$nameKey])) {
            $author['email'] = $isoContactInfo[$nameKey]['email'];
            $author['website'] = $isoContactInfo[$nameKey]['website'];
        } else {
            $author['email'] = $author['email'] ?? '';
            $author['website'] = $author['website'] ?? '';
        }
    }

    private static function buildNameKey(string $familyName, string $firstName): string
    {
        return mb_strtolower(trim($familyName).', '.trim($firstName));
    }
}
