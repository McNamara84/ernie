<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Exceptions\DuplicateUploadedResourceDoiException;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\Right;
use App\Models\TitleType;
use App\Services\DoiSuggestionService;
use App\Services\ResourceStorageService;
use Illuminate\Support\Str;

final class UploadedResourceDraftService
{
    public function __construct(
        private readonly ResourceStorageService $resourceStorageService,
        private readonly DoiSuggestionService $doiSuggestionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeFromPayload(array $payload, string $filename, ?int $userId): Resource
    {
        $this->ensureControlledVocabularyRows();

        $storagePayload = $this->buildStoragePayload($payload, $filename);

        $doi = $storagePayload['doi'] ?? null;
        if (is_string($doi) && $doi !== '') {
            $existingResourceId = Resource::query()
                ->where('doi', $doi)
                ->value('id');

            if ($existingResourceId !== null) {
                throw new DuplicateUploadedResourceDoiException($doi, (int) $existingResourceId);
            }
        }

        [$resource] = $this->resourceStorageService->store($storagePayload, $userId);

        return $resource;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildStoragePayload(array $payload, string $filename): array
    {
        return [
            'resourceId' => null,
            'doi' => $this->normalizeDoi($payload['doi'] ?? null),
            'year' => $this->stringOrNull($payload['year'] ?? null),
            'resourceType' => $payload['resourceType'] ?? null,
            'version' => $this->stringOrNull($payload['version'] ?? null),
            'language' => $this->stringOrNull($payload['language'] ?? null),
            'titles' => $this->titleList($payload['titles'] ?? [], $filename),
            'licenses' => $this->knownLicenseIdentifiers($payload['licenses'] ?? []),
            'rawRights' => $this->arrayList($payload['rawRights'] ?? []),
            'authors' => $this->positionedList($payload['authors'] ?? []),
            'contributors' => $this->positionedList($payload['contributors'] ?? []),
            'descriptions' => $this->descriptionList($payload['descriptions'] ?? []),
            'dates' => $this->dateList($payload['dates'] ?? []),
            'importedCreatedDate' => $this->importedCreatedDate($payload['dates'] ?? []),
            'freeKeywords' => $this->freeKeywords($payload['freeKeywords'] ?? []),
            'gcmdKeywords' => $this->controlledKeywords($payload),
            'spatialTemporalCoverages' => $this->coverageList($payload),
            'relatedIdentifiers' => $this->relatedIdentifierList($payload),
            'relatedItems' => $this->arrayList($payload['relatedItems'] ?? []),
            'fundingReferences' => $this->positionedList($payload['fundingReferences'] ?? []),
            'mslLaboratories' => $this->mslLaboratoryList($payload['mslLaboratories'] ?? []),
            'instruments' => $this->positionedList($payload['instruments'] ?? []),
            'datacenters' => [],
        ];
    }

    private function normalizeDoi(mixed $doi): ?string
    {
        if (! is_string($doi) && ! is_numeric($doi)) {
            return null;
        }

        $normalized = $this->doiSuggestionService->normalizeDoi((string) $doi);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function titleList(mixed $titles, string $filename): array
    {
        $normalized = [];
        $hasMainTitle = false;

        foreach ($this->arrayList($titles) as $index => $title) {
            if (! is_array($title)) {
                continue;
            }

            $value = $this->stringOrNull($title['title'] ?? $title['value'] ?? null);
            if ($value === null) {
                continue;
            }

            $titleType = $this->stringOrNull($title['titleType'] ?? $title['type'] ?? null) ?? 'main-title';
            if (Str::kebab($titleType) === 'main-title') {
                $hasMainTitle = true;
            }

            $normalized[] = [
                'title' => $this->limit($value),
                'titleType' => $titleType,
                'language' => $this->stringOrNull($title['language'] ?? null),
                'position' => $index,
            ];
        }

        if (! $hasMainTitle) {
            array_unshift($normalized, [
                'title' => $this->fallbackTitle($filename),
                'titleType' => 'main-title',
                'language' => null,
                'position' => 0,
            ]);

            foreach ($normalized as $index => &$title) {
                $title['position'] = $index;
            }
            unset($title);
        }

        return $normalized;
    }

    private function fallbackTitle(string $filename): string
    {
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $title = preg_replace('/[_\-.]+/', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim($title);

        return $this->limit($title === '' ? 'Untitled upload' : $title);
    }

    /**
     * @return array<int, string>
     */
    private function knownLicenseIdentifiers(mixed $licenses): array
    {
        $identifiers = [];

        foreach ($this->arrayList($licenses) as $license) {
            if (is_array($license)) {
                $identifier = $this->stringOrNull($license['identifier'] ?? $license['rightsIdentifier'] ?? null);
            } else {
                $identifier = $this->stringOrNull($license);
            }

            if ($identifier !== null) {
                $identifiers[] = $identifier;
            }
        }

        $identifiers = array_values(array_unique($identifiers));
        if ($identifiers === []) {
            return [];
        }

        return Right::query()
            ->whereIn('identifier', $identifiers)
            ->pluck('identifier')
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function descriptionList(mixed $items): array
    {
        $descriptions = [];

        foreach ($this->arrayList($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $description = $this->stringOrNull($item['description'] ?? $item['value'] ?? null);
            if ($description === null) {
                continue;
            }

            $descriptions[] = [
                'description' => $description,
                'descriptionType' => $this->stringOrNull($item['descriptionType'] ?? $item['type'] ?? null) ?? 'Other',
                'language' => $this->stringOrNull($item['language'] ?? null),
                'position' => $index,
            ];
        }

        return $descriptions;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function controlledKeywords(array $payload): array
    {
        $keywords = [];

        foreach (['gcmdKeywords', 'mslKeywords', 'gemetKeywords'] as $key) {
            foreach ($this->arrayList($payload[$key] ?? []) as $keyword) {
                if (! is_array($keyword)) {
                    continue;
                }

                $id = $this->stringOrNull($keyword['id'] ?? null);
                $text = $this->stringOrNull($keyword['text'] ?? null);
                $path = $this->stringOrNull($keyword['path'] ?? null) ?? $text;
                $scheme = $this->stringOrNull($keyword['scheme'] ?? null);

                if ($id === null || $text === null || $path === null || $scheme === null) {
                    continue;
                }

                $keywords[] = [
                    'id' => $id,
                    'text' => $text,
                    'path' => $path,
                    'scheme' => $scheme,
                    'schemeURI' => $this->stringOrNull($keyword['schemeURI'] ?? $keyword['schemeUri'] ?? null),
                    'language' => $this->stringOrNull($keyword['language'] ?? null),
                ];
            }
        }

        return $keywords;
    }

    /**
     * @return array<int, string>
     */
    private function freeKeywords(mixed $items): array
    {
        $keywords = [];

        foreach ($this->arrayList($items) as $item) {
            $keyword = $this->stringOrNull($item);
            if ($keyword !== null) {
                $keywords[] = $keyword;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dateList(mixed $items): array
    {
        $dates = [];

        foreach ($this->arrayList($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $dateType = $this->stringOrNull($item['dateType'] ?? null);
            $dateTypeKey = $dateType !== null ? Str::kebab($dateType) : null;
            $startDate = $this->stringOrNull($item['startDate'] ?? null);
            $endDate = $this->stringOrNull($item['endDate'] ?? null);

            if ($dateTypeKey === null || in_array($dateTypeKey, ['coverage', 'created', 'updated'], true)) {
                continue;
            }

            if ($startDate === null && $endDate === null) {
                continue;
            }

            $supportsPeriod = in_array($dateTypeKey, ['collected', 'valid', 'other'], true);
            if ($endDate !== null && ($startDate === null || ! $supportsPeriod)) {
                continue;
            }

            $date = $item;
            $date['dateType'] = $dateType;
            $date['startDate'] = $startDate;
            $date['endDate'] = $endDate;
            $date['position'] = $item['position'] ?? $index;

            $dates[] = $date;
        }

        return $dates;
    }

    private function importedCreatedDate(mixed $items): ?string
    {
        foreach ($this->arrayList($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $dateType = $this->stringOrNull($item['dateType'] ?? null);
            if ($dateType === null || Str::kebab($dateType) !== 'created') {
                continue;
            }

            $startDate = $this->stringOrNull($item['startDate'] ?? null);
            if ($startDate !== null) {
                return $startDate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function coverageList(array $payload): array
    {
        $coverages = [];

        foreach ($this->arrayList($payload['spatialTemporalCoverages'] ?? $payload['coverages'] ?? []) as $index => $coverage) {
            if (! is_array($coverage)) {
                continue;
            }

            $coverage['position'] = $coverage['position'] ?? $index;
            $coverage['type'] = $coverage['type'] ?? $this->inferCoverageType($coverage);
            $coverages[] = $coverage;
        }

        return $coverages;
    }

    /**
     * @param  array<string, mixed>  $coverage
     */
    private function inferCoverageType(array $coverage): string
    {
        if (! empty($coverage['polygonPoints'])) {
            return 'polygon';
        }

        if (($coverage['latMax'] ?? '') !== '' || ($coverage['lonMax'] ?? '') !== '') {
            return 'box';
        }

        return 'point';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function relatedIdentifierList(array $payload): array
    {
        $items = $this->arrayList($payload['relatedIdentifiers'] ?? []);
        $items = array_merge($items, $this->arrayList($payload['relatedWorks'] ?? []));
        $relatedIdentifiers = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $identifier = $this->stringOrNull($item['identifier'] ?? null);
            $identifierType = $this->stringOrNull($item['identifierType'] ?? $item['identifier_type'] ?? null);
            $relationType = $this->stringOrNull($item['relationType'] ?? $item['relation_type'] ?? null);

            if ($identifier === null || $identifierType === null || $relationType === null) {
                continue;
            }

            $relatedIdentifiers[] = [
                'identifier' => $identifier,
                'identifierType' => $identifierType,
                'relationType' => $relationType,
                'relationTypeInformation' => $this->stringOrNull($item['relationTypeInformation'] ?? $item['relation_type_information'] ?? null),
                'citationLabel' => $this->stringOrNull($item['citationLabel'] ?? $item['citation_label'] ?? null),
                'position' => $item['position'] ?? $index,
            ];
        }

        return $relatedIdentifiers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mslLaboratoryList(mixed $items): array
    {
        $laboratories = [];

        foreach ($this->arrayList($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $identifier = $this->stringOrNull($item['identifier'] ?? $item['labId'] ?? null);
            $name = $this->stringOrNull($item['name'] ?? $item['labName'] ?? null);

            if ($identifier === null && $name === null) {
                continue;
            }

            $laboratories[] = [
                'identifier' => $identifier ?? $name,
                'name' => $name ?? $identifier,
                'affiliation_name' => $this->stringOrNull($item['affiliation_name'] ?? $item['affiliationName'] ?? null) ?? '',
                'affiliation_ror' => $this->stringOrNull($item['affiliation_ror'] ?? $item['affiliationRor'] ?? null),
                'position' => $item['position'] ?? $index,
            ];
        }

        return $laboratories;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function positionedList(mixed $items): array
    {
        $positioned = [];

        foreach ($this->arrayList($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $item['position'] = $item['position'] ?? $index;
            $positioned[] = $item;
        }

        return $positioned;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limit(string $value): string
    {
        return Str::limit($value, 255, '');
    }

    private function ensureControlledVocabularyRows(): void
    {
        $this->ensureTitleTypes();
        $this->ensureDescriptionTypes();
        $this->ensureDateTypes();
        $this->ensureIdentifierTypes();
        $this->ensureRelationTypes();
    }

    private function ensureTitleTypes(): void
    {
        foreach ([
            ['name' => 'Main Title', 'slug' => 'MainTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle'],
            ['name' => 'Subtitle', 'slug' => 'Subtitle'],
            ['name' => 'Translated Title', 'slug' => 'TranslatedTitle'],
            ['name' => 'Other', 'slug' => 'Other'],
        ] as $type) {
            TitleType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }

    private function ensureDescriptionTypes(): void
    {
        foreach ([
            ['name' => 'Abstract', 'slug' => 'Abstract'],
            ['name' => 'Methods', 'slug' => 'Methods'],
            ['name' => 'Series Information', 'slug' => 'SeriesInformation'],
            ['name' => 'Table of Contents', 'slug' => 'TableOfContents'],
            ['name' => 'Technical Info', 'slug' => 'TechnicalInfo'],
            ['name' => 'Other', 'slug' => 'Other'],
        ] as $type) {
            DescriptionType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }

    private function ensureDateTypes(): void
    {
        foreach ([
            ['name' => 'Accepted', 'slug' => 'Accepted'],
            ['name' => 'Available', 'slug' => 'Available'],
            ['name' => 'Copyrighted', 'slug' => 'Copyrighted'],
            ['name' => 'Collected', 'slug' => 'Collected'],
            ['name' => 'Coverage', 'slug' => 'Coverage', 'is_active' => false],
            ['name' => 'Created', 'slug' => 'Created'],
            ['name' => 'Issued', 'slug' => 'Issued'],
            ['name' => 'Submitted', 'slug' => 'Submitted'],
            ['name' => 'Updated', 'slug' => 'Updated'],
            ['name' => 'Valid', 'slug' => 'Valid'],
            ['name' => 'Withdrawn', 'slug' => 'Withdrawn'],
            ['name' => 'Other', 'slug' => 'Other'],
        ] as $type) {
            DateType::query()->firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'is_active' => $type['is_active'] ?? true,
                ],
            );
        }
    }

    private function ensureIdentifierTypes(): void
    {
        foreach ([
            ['name' => 'ARK', 'slug' => 'ARK'],
            ['name' => 'arXiv', 'slug' => 'arXiv'],
            ['name' => 'bibcode', 'slug' => 'bibcode'],
            ['name' => 'CSTR', 'slug' => 'CSTR'],
            ['name' => 'DOI', 'slug' => 'DOI'],
            ['name' => 'EAN13', 'slug' => 'EAN13'],
            ['name' => 'EISSN', 'slug' => 'EISSN'],
            ['name' => 'Handle', 'slug' => 'Handle'],
            ['name' => 'IGSN', 'slug' => 'IGSN'],
            ['name' => 'ISBN', 'slug' => 'ISBN'],
            ['name' => 'ISSN', 'slug' => 'ISSN'],
            ['name' => 'ISTC', 'slug' => 'ISTC'],
            ['name' => 'LISSN', 'slug' => 'LISSN'],
            ['name' => 'LSID', 'slug' => 'LSID'],
            ['name' => 'PMID', 'slug' => 'PMID'],
            ['name' => 'PURL', 'slug' => 'PURL'],
            ['name' => 'RAiD', 'slug' => 'RAiD'],
            ['name' => 'RRID', 'slug' => 'RRID'],
            ['name' => 'SWHID', 'slug' => 'SWHID'],
            ['name' => 'UPC', 'slug' => 'UPC'],
            ['name' => 'URL', 'slug' => 'URL'],
            ['name' => 'URN', 'slug' => 'URN'],
            ['name' => 'w3id', 'slug' => 'w3id'],
        ] as $type) {
            IdentifierType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }

    private function ensureRelationTypes(): void
    {
        foreach ([
            ['name' => 'Is Cited By', 'slug' => 'IsCitedBy'],
            ['name' => 'Cites', 'slug' => 'Cites'],
            ['name' => 'Is Supplement To', 'slug' => 'IsSupplementTo'],
            ['name' => 'Is Supplemented By', 'slug' => 'IsSupplementedBy'],
            ['name' => 'Is Translation Of', 'slug' => 'IsTranslationOf'],
            ['name' => 'Has Translation', 'slug' => 'HasTranslation'],
            ['name' => 'Is Continued By', 'slug' => 'IsContinuedBy'],
            ['name' => 'Continues', 'slug' => 'Continues'],
            ['name' => 'Is Described By', 'slug' => 'IsDescribedBy'],
            ['name' => 'Describes', 'slug' => 'Describes'],
            ['name' => 'Has Metadata', 'slug' => 'HasMetadata'],
            ['name' => 'Is Metadata For', 'slug' => 'IsMetadataFor'],
            ['name' => 'Has Version', 'slug' => 'HasVersion'],
            ['name' => 'Is Version Of', 'slug' => 'IsVersionOf'],
            ['name' => 'Is New Version Of', 'slug' => 'IsNewVersionOf'],
            ['name' => 'Is Previous Version Of', 'slug' => 'IsPreviousVersionOf'],
            ['name' => 'Is Part Of', 'slug' => 'IsPartOf'],
            ['name' => 'Has Part', 'slug' => 'HasPart'],
            ['name' => 'Is Published In', 'slug' => 'IsPublishedIn'],
            ['name' => 'Is Referenced By', 'slug' => 'IsReferencedBy'],
            ['name' => 'References', 'slug' => 'References'],
            ['name' => 'Is Documented By', 'slug' => 'IsDocumentedBy'],
            ['name' => 'Documents', 'slug' => 'Documents'],
            ['name' => 'Is Compiled By', 'slug' => 'IsCompiledBy'],
            ['name' => 'Compiles', 'slug' => 'Compiles'],
            ['name' => 'Is Variant Form Of', 'slug' => 'IsVariantFormOf'],
            ['name' => 'Is Original Form Of', 'slug' => 'IsOriginalFormOf'],
            ['name' => 'Is Identical To', 'slug' => 'IsIdenticalTo'],
            ['name' => 'Is Reviewed By', 'slug' => 'IsReviewedBy'],
            ['name' => 'Reviews', 'slug' => 'Reviews'],
            ['name' => 'Is Derived From', 'slug' => 'IsDerivedFrom'],
            ['name' => 'Is Source Of', 'slug' => 'IsSourceOf'],
            ['name' => 'Is Required By', 'slug' => 'IsRequiredBy'],
            ['name' => 'Requires', 'slug' => 'Requires'],
            ['name' => 'Is Obsoleted By', 'slug' => 'IsObsoletedBy'],
            ['name' => 'Obsoletes', 'slug' => 'Obsoletes'],
            ['name' => 'Is Collected By', 'slug' => 'IsCollectedBy'],
            ['name' => 'Collects', 'slug' => 'Collects'],
            ['name' => 'Other', 'slug' => 'Other'],
        ] as $type) {
            RelationType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }
}
