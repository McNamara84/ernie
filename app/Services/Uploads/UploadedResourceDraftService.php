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
use Illuminate\Database\QueryException;
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
        $storagePayload = $this->buildStoragePayload($payload, $filename);
        $this->ensureControlledVocabularyRows($storagePayload);

        $doi = $storagePayload['doi'] ?? null;
        if (is_string($doi) && $doi !== '') {
            $existingResourceId = Resource::query()
                ->where('doi', $doi)
                ->value('id');

            if ($existingResourceId !== null) {
                throw new DuplicateUploadedResourceDoiException($doi, (int) $existingResourceId);
            }
        }

        try {
            [$resource] = $this->resourceStorageService->store($storagePayload, $userId);
        } catch (QueryException $e) {
            $this->throwDuplicateDoiExceptionIfUniqueDoiViolation($e, $doi);

            throw $e;
        }

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

    private function throwDuplicateDoiExceptionIfUniqueDoiViolation(QueryException $exception, mixed $doi): void
    {
        if (! is_string($doi) || $doi === '' || ! $this->isResourceDoiUniqueViolation($exception)) {
            return;
        }

        $existingResourceId = Resource::query()
            ->where('doi', $doi)
            ->value('id');

        if ($existingResourceId !== null) {
            throw new DuplicateUploadedResourceDoiException($doi, (int) $existingResourceId);
        }
    }

    private function isResourceDoiUniqueViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = isset($errorInfo[0]) ? (string) $errorInfo[0] : '';
        $driverCode = isset($errorInfo[1]) ? (string) $errorInfo[1] : '';

        if (! in_array($sqlState, ['23000', '23505'], true) && ! in_array($driverCode, ['19', '1062'], true)) {
            return false;
        }

        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'resources.doi')
            || str_contains($message, 'resources_doi_unique')
            || (str_contains($message, 'duplicate entry') && str_contains($message, 'doi'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureControlledVocabularyRows(array $payload): void
    {
        $this->ensureTitleTypes(array_merge(
            ['main-title'],
            $this->typeValues($payload['titles'] ?? [], 'titleType'),
        ));
        $this->ensureDescriptionTypes($this->typeValues($payload['descriptions'] ?? [], 'descriptionType'));
        $this->ensureDateTypes(array_merge(
            ['Created'],
            $this->typeValues($payload['dates'] ?? [], 'dateType'),
        ));
        $this->ensureIdentifierTypes($this->typeValues($payload['relatedIdentifiers'] ?? [], 'identifierType'));
        $this->ensureRelationTypes(array_merge(
            $this->typeValues($payload['relatedIdentifiers'] ?? [], 'relationType'),
            $this->typeValues($payload['relatedItems'] ?? [], 'relation_type_slug'),
        ));
    }

    /**
     * @param  list<string>  $values
     */
    private function ensureTitleTypes(array $values): void
    {
        foreach ($this->matchingControlledRows($values, [
            ['name' => 'Main Title', 'slug' => 'MainTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle'],
            ['name' => 'Subtitle', 'slug' => 'Subtitle'],
            ['name' => 'Translated Title', 'slug' => 'TranslatedTitle'],
            ['name' => 'Other', 'slug' => 'Other'],
        ]) as $type) {
            TitleType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function ensureDescriptionTypes(array $values): void
    {
        foreach ($this->matchingControlledRows($values, [
            ['name' => 'Abstract', 'slug' => 'Abstract'],
            ['name' => 'Methods', 'slug' => 'Methods'],
            ['name' => 'Series Information', 'slug' => 'SeriesInformation'],
            ['name' => 'Table of Contents', 'slug' => 'TableOfContents'],
            ['name' => 'Technical Info', 'slug' => 'TechnicalInfo'],
            ['name' => 'Other', 'slug' => 'Other'],
        ]) as $type) {
            DescriptionType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function ensureDateTypes(array $values): void
    {
        foreach ($this->matchingControlledRows($values, [
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
        ]) as $type) {
            DateType::query()->firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'is_active' => $type['is_active'] ?? true,
                ],
            );
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function ensureIdentifierTypes(array $values): void
    {
        foreach ($this->matchingControlledRows($values, [
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
        ]) as $type) {
            IdentifierType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function ensureRelationTypes(array $values): void
    {
        foreach ($this->matchingControlledRows($values, [
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
        ]) as $type) {
            RelationType::query()->firstOrCreate(['slug' => $type['slug']], ['name' => $type['name']]);
        }
    }

    /**
     * @return list<string>
     */
    private function typeValues(mixed $items, string ...$keys): array
    {
        $values = [];

        foreach ($this->arrayList($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($keys as $key) {
                $value = $this->stringOrNull($item[$key] ?? null);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<string>  $values
     * @param  list<array{name: string, slug: string, is_active?: bool}>  $rows
     * @return list<array{name: string, slug: string, is_active?: bool}>
     */
    private function matchingControlledRows(array $values, array $rows): array
    {
        $requestedKeys = [];

        foreach ($values as $value) {
            $key = $this->controlledRowKey($value);
            if ($key !== null) {
                $requestedKeys[$key] = true;
            }
        }

        if ($requestedKeys === []) {
            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            $slugKey = $this->controlledRowKey($row['slug']);
            $nameKey = $this->controlledRowKey($row['name']);

            if (($slugKey !== null && isset($requestedKeys[$slugKey])) || ($nameKey !== null && isset($requestedKeys[$nameKey]))) {
                $matches[$row['slug']] = $row;
            }
        }

        return array_values($matches);
    }

    private function controlledRowKey(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = preg_replace('/[^[:alnum:]]+/u', '', trim((string) $value)) ?? '';

        return $normalized === '' ? null : Str::lower($normalized);
    }
}
