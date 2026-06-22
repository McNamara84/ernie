<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Exceptions\DuplicateUploadedResourceDoiException;
use App\Models\Resource;
use App\Models\Right;
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
            'dates' => $this->positionedList($payload['dates'] ?? []),
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
     * @param  mixed  $titles
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
     * @param  mixed  $licenses
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
     * @param  mixed  $items
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
     * @param  mixed  $items
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
     * @param  mixed  $items
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
                'affiliation_name' => $this->stringOrNull($item['affiliation_name'] ?? $item['affiliationName'] ?? null),
                'affiliation_ror' => $this->stringOrNull($item['affiliation_ror'] ?? $item['affiliationRor'] ?? null),
                'position' => $item['position'] ?? $index,
            ];
        }

        return $laboratories;
    }

    /**
     * @param  mixed  $items
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
     * @param  mixed  $value
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
}
