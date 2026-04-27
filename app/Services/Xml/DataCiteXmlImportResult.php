<?php

declare(strict_types=1);

namespace App\Services\Xml;

/**
 * Result of parsing a DataCite XML upload into the editor session payload.
 */
final readonly class DataCiteXmlImportResult
{
    /**
     * @param  array<int, array{title: mixed, titleType: string, language: string|null}>  $titles
     * @param  array<int, string>  $licenses
     * @param  array<int, array<string, mixed>>  $authors
     * @param  array<int, array<string, mixed>>  $contributors
     * @param  array<int, array<string, mixed>>  $descriptions
     * @param  array<int, array<string, mixed>>  $dates
     * @param  array<int, array<string, mixed>>  $coverages
     * @param  array<int, array<string, mixed>>  $relatedWorks
     * @param  array<int, array<string, mixed>>  $instruments
     * @param  array<int, array<string, mixed>>  $gcmdKeywords
     * @param  array<int, string>  $freeKeywords
     * @param  array<int, array<string, mixed>>  $mslKeywords
     * @param  array<int, array<string, mixed>>  $gemetKeywords
     * @param  array<int, array<string, mixed>>  $fundingReferences
     * @param  array<int, array<string, string>>  $mslLaboratories
     * @param  array<int, array<string, mixed>>  $relatedItems
     */
    public function __construct(
        public ?string $doi,
        public ?string $year,
        public ?string $version,
        public ?string $language,
        public ?string $resourceType,
        public array $titles,
        public array $licenses,
        public array $authors,
        public array $contributors,
        public array $descriptions,
        public array $dates,
        public array $coverages,
        public array $relatedWorks,
        public array $instruments,
        public array $gcmdKeywords,
        public array $freeKeywords,
        public array $mslKeywords,
        public array $gemetKeywords,
        public array $fundingReferences,
        public array $mslLaboratories,
        public array $relatedItems,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSessionPayload(): array
    {
        return [
            'doi' => $this->doi,
            'year' => $this->year,
            'version' => $this->version,
            'language' => $this->language,
            'resourceType' => $this->resourceType,
            'titles' => $this->titles,
            'licenses' => $this->licenses,
            'authors' => $this->authors,
            'contributors' => $this->contributors,
            'descriptions' => $this->descriptions,
            'dates' => $this->dates,
            'coverages' => $this->coverages,
            'relatedWorks' => $this->relatedWorks,
            'instruments' => $this->instruments,
            'gcmdKeywords' => $this->gcmdKeywords,
            'freeKeywords' => $this->freeKeywords,
            'mslKeywords' => $this->mslKeywords,
            'gemetKeywords' => $this->gemetKeywords,
            'fundingReferences' => $this->fundingReferences,
            'mslLaboratories' => $this->mslLaboratories,
            'relatedItems' => $this->relatedItems,
        ];
    }
}
