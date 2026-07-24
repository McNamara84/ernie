<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Datacenter;
use App\Models\OldDataset;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SumarioPendingResourceImportService
{
    public function __construct(
        private readonly OldDatasetEditorLoader $editorLoader,
        private readonly ResourceStorageService $resourceStorage,
        private readonly LegacyMetaworksDatacenterLookupService $datacenterLookup,
        private readonly MetaworksDownloadUrlService $downloadUrlService,
        private readonly LegacyLandingPageImportService $landingPageImport,
        private readonly DoiSuggestionService $doiSuggestionService,
    ) {}

    public function countImportablePending(): int
    {
        return OldDataset::query()
            ->where('publicstatus', 'pending')
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '')
            ->count();
    }

    /**
     * @return array{status: 'imported'|'skipped'|'missing'|'failed', resource: Resource|null, doi: string, error: string|null}
     */
    public function importPendingByDoi(string $doi, int $userId): array
    {
        $normalisedDoi = $this->normaliseDoi($doi);

        if ($this->shouldSkipLegacyDoi($normalisedDoi)) {
            return [
                'status' => 'skipped',
                'resource' => null,
                'doi' => $normalisedDoi,
                'error' => null,
            ];
        }

        $oldDataset = $this->findPendingDatasetByDoi($normalisedDoi);

        if ($oldDataset === null) {
            return [
                'status' => 'missing',
                'resource' => null,
                'doi' => $normalisedDoi,
                'error' => null,
            ];
        }

        if (Resource::where('doi', $normalisedDoi)->exists()) {
            return [
                'status' => 'skipped',
                'resource' => null,
                'doi' => $normalisedDoi,
                'error' => null,
            ];
        }

        try {
            return [
                'status' => 'imported',
                'resource' => $this->importDataset($oldDataset, $normalisedDoi, $userId),
                'doi' => $normalisedDoi,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Failed to import SUMARIO pending resource', [
                'doi' => $normalisedDoi,
                'old_resource_id' => $oldDataset->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'resource' => null,
                'doi' => $normalisedDoi,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return list<string>
     */
    public function importablePendingDoisForDatacenter(string $datacenterName): array
    {
        $datacenterName = trim($datacenterName);

        if ($datacenterName === '') {
            return [];
        }

        /** @var iterable<int, OldDataset> $pendingDatasets */
        $pendingDatasets = OldDataset::query()
            ->where('publicstatus', 'pending')
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '')
            ->orderBy('id')
            ->cursor();

        $dois = [];
        $seenDois = [];

        foreach ($pendingDatasets as $oldDataset) {
            $doi = $this->normaliseDoi((string) $oldDataset->identifier);

            if ($doi === '' || isset($seenDois[$doi]) || $this->shouldSkipLegacyDoi($doi)) {
                continue;
            }

            $seenDois[$doi] = true;

            if (! in_array($datacenterName, $this->datacenterLookup->resolveDatacenterNames($doi), true)) {
                continue;
            }

            $dois[$doi] = true;
        }

        $dois = array_keys($dois);
        sort($dois);

        return $dois;
    }

    /**
     * @return array{processed: int, imported: int, skipped: int, failed: int, skipped_dois: list<string>, failed_dois: list<array{doi: string, error: string}>}
     */
    public function importAllPending(int $userId, int $maxStoredDois = 100): array
    {
        $summary = [
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
        ];

        /** @var iterable<int, OldDataset> $pendingDatasets */
        $pendingDatasets = OldDataset::query()
            ->where('publicstatus', 'pending')
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '')
            ->orderBy('id')
            ->cursor();

        foreach ($pendingDatasets as $oldDataset) {
            $summary['processed']++;
            $doi = $this->normaliseDoi((string) $oldDataset->identifier);

            if ($this->shouldSkipLegacyDoi($doi)) {
                $summary['skipped']++;
                if (count($summary['skipped_dois']) < $maxStoredDois) {
                    $summary['skipped_dois'][] = $doi;
                }

                continue;
            }

            if (Resource::where('doi', $doi)->exists()) {
                $summary['skipped']++;
                if (count($summary['skipped_dois']) < $maxStoredDois) {
                    $summary['skipped_dois'][] = $doi;
                }

                continue;
            }

            try {
                $this->importDataset($oldDataset, $doi, $userId);
                $summary['imported']++;
            } catch (\Throwable $exception) {
                $summary['failed']++;

                if (count($summary['failed_dois']) < $maxStoredDois) {
                    $summary['failed_dois'][] = [
                        'doi' => $doi,
                        'error' => $exception->getMessage(),
                    ];
                }

                Log::warning('Failed to import SUMARIO pending resource', [
                    'doi' => $doi,
                    'old_resource_id' => $oldDataset->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    private function findPendingDatasetByDoi(string $doi): ?OldDataset
    {
        return OldDataset::query()
            ->where('publicstatus', 'pending')
            ->where('identifier', $doi)
            ->first();
    }

    private function importDataset(OldDataset $oldDataset, string $doi, int $userId): Resource
    {
        $editorData = $this->editorLoader->loadForEditor((int) $oldDataset->id);
        $payload = $this->mapEditorPayloadForStorage($editorData, $oldDataset, $doi);

        [$resource] = $this->resourceStorage->store($payload, $userId);

        $resource->forceFill([
            'legacy_source' => 'sumario-pmd',
            'legacy_source_id' => $oldDataset->id,
            'legacy_source_status' => $oldDataset->publicstatus,
            'force_review_status' => true,
        ])->save();

        $fileResult = ['files' => [], 'allPublic' => false];

        try {
            $fileResult = $this->downloadUrlService->lookupFileEntries($doi);
        } catch (\Throwable $exception) {
            Log::warning('SUMARIO pending import could not load legacy file URLs', [
                'doi' => $doi,
                'old_resource_id' => $oldDataset->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->landingPageImport->createForResource(
            resource: $resource,
            fileEntries: $fileResult['files'],
            isPublished: false,
            createWhenEmpty: true,
        );

        return $resource->fresh(['landingPage', 'datacenter']) ?? $resource;
    }

    /**
     * @param  array<string, mixed>  $editorData
     * @return array<string, mixed>
     */
    private function mapEditorPayloadForStorage(array $editorData, OldDataset $oldDataset, string $doi): array
    {
        return [
            'resourceId' => null,
            'doi' => $doi,
            'year' => $this->normaliseYear($editorData['year'] ?? $oldDataset->publicationyear),
            'version' => $this->filledString($editorData['version'] ?? null),
            'language' => $this->filledString($editorData['language'] ?? null) ?? 'en',
            'resourceType' => $this->resolveResourceTypeId($editorData['resourceType'] ?? null),
            'titles' => $this->normaliseTitles($editorData['titles'] ?? [], $oldDataset, $doi),
            'licenses' => $this->normaliseStringList($editorData['initialRights'] ?? []),
            'rawRights' => is_array($editorData['initialRawRights'] ?? null) ? array_values($editorData['initialRawRights']) : [],
            'authors' => $this->normaliseContributors($editorData['authors'] ?? []),
            'contributors' => $this->normaliseContributors($editorData['contributors'] ?? [], true),
            'descriptions' => $this->normaliseDescriptions($editorData['descriptions'] ?? []),
            'dates' => $this->normaliseDates($editorData['dates'] ?? []),
            'gcmdKeywords' => is_array($editorData['gcmdKeywords'] ?? null) ? array_values($editorData['gcmdKeywords']) : [],
            'freeKeywords' => $this->normaliseStringList($editorData['freeKeywords'] ?? []),
            'spatialTemporalCoverages' => is_array($editorData['geoLocations'] ?? null) ? array_values($editorData['geoLocations']) : [],
            'relatedIdentifiers' => $this->normaliseRelatedIdentifiers($editorData['relatedWorks'] ?? []),
            'fundingReferences' => is_array($editorData['fundingReferences'] ?? null) ? array_values($editorData['fundingReferences']) : [],
            'mslLaboratories' => is_array($editorData['mslLaboratories'] ?? null) ? array_values($editorData['mslLaboratories']) : [],
            'datacenter_id' => $this->datacenterIdForDoi($doi),
        ];
    }

    /**
     * @return list<array{title: string, titleType: string, language?: string|null}>
     */
    private function normaliseTitles(mixed $titles, OldDataset $oldDataset, string $doi): array
    {
        $normalised = [];

        if (is_array($titles)) {
            foreach ($titles as $title) {
                if (! is_array($title)) {
                    continue;
                }

                $value = $this->filledString($title['title'] ?? null);

                if ($value === null) {
                    continue;
                }

                $normalised[] = [
                    'title' => $value,
                    'titleType' => $this->filledString($title['titleType'] ?? null) ?? 'main-title',
                    'language' => $this->filledString($title['language'] ?? null),
                ];
            }
        }

        if ($normalised === []) {
            $normalised[] = [
                'title' => $this->filledString($oldDataset->title ?? null) ?? "Legacy dataset {$doi}",
                'titleType' => 'main-title',
            ];
        }

        return $normalised;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normaliseContributors(mixed $contributors, bool $mapRoles = false): array
    {
        if (! is_array($contributors)) {
            return [];
        }

        $normalised = [];

        foreach (array_values($contributors) as $position => $contributor) {
            if (! is_array($contributor)) {
                continue;
            }

            $contributor['position'] = isset($contributor['position']) && is_numeric($contributor['position'])
                ? (int) $contributor['position']
                : $position;

            if ($mapRoles) {
                $contributor['roles'] = $this->normaliseRoles($contributor['roles'] ?? []);
            }

            $normalised[] = $contributor;
        }

        return $normalised;
    }

    /**
     * @return list<string>
     */
    private function normaliseRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return ['Other'];
        }

        $normalised = [];

        foreach ($roles as $role) {
            $role = $this->filledString($role);

            if ($role === null) {
                continue;
            }

            $normalised[] = Str::studly($role);
        }

        return $normalised !== [] ? array_values(array_unique($normalised)) : ['Other'];
    }

    /**
     * @return list<array{descriptionType: string, description: string, language?: string|null}>
     */
    private function normaliseDescriptions(mixed $descriptions): array
    {
        if (! is_array($descriptions)) {
            return [];
        }

        $normalised = [];

        foreach ($descriptions as $description) {
            if (! is_array($description)) {
                continue;
            }

            $value = $this->filledString($description['description'] ?? null);

            if ($value === null) {
                continue;
            }

            $normalised[] = [
                'descriptionType' => Str::kebab((string) ($description['descriptionType'] ?? $description['type'] ?? 'abstract')),
                'description' => $value,
                'language' => $this->filledString($description['language'] ?? null),
            ];
        }

        return $normalised;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normaliseDates(mixed $dates): array
    {
        if (! is_array($dates)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $date): ?array {
            if (! is_array($date)) {
                return null;
            }

            $date['dateType'] = Str::kebab((string) ($date['dateType'] ?? ''));

            return $date['dateType'] !== '' ? $date : null;
        }, $dates)));
    }

    /**
     * @return list<array{identifier: string, identifierType: string, relationType: string, citationLabel: string}>
     */
    private function normaliseRelatedIdentifiers(mixed $relatedIdentifiers): array
    {
        if (! is_array($relatedIdentifiers)) {
            return [];
        }

        $normalised = [];

        foreach ($relatedIdentifiers as $relatedIdentifier) {
            if (! is_array($relatedIdentifier)) {
                continue;
            }

            $identifier = $this->filledString($relatedIdentifier['identifier'] ?? null);

            if ($identifier === null) {
                continue;
            }

            $normalised[] = [
                'identifier' => $identifier,
                'identifierType' => $this->filledString($relatedIdentifier['identifierType'] ?? $relatedIdentifier['identifier_type'] ?? null) ?? 'DOI',
                'relationType' => $this->filledString($relatedIdentifier['relationType'] ?? $relatedIdentifier['relation_type'] ?? null) ?? 'References',
                'citationLabel' => $identifier,
            ];
        }

        return $normalised;
    }

    /**
     * @return list<string>
     */
    private function normaliseStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->filledString($value),
            $values,
        ))));
    }

    private function datacenterIdForDoi(string $doi): int
    {
        $ids = $this->datacenterLookup->resolveDatacenterIds($doi);

        if ($ids !== []) {
            return $ids[0];
        }

        return (int) Datacenter::query()->firstOrCreate([
            'name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ])->id;
    }

    private function resolveResourceTypeId(mixed $resourceType): ?int
    {
        if (is_numeric($resourceType) && ResourceType::whereKey((int) $resourceType)->exists()) {
            return (int) $resourceType;
        }

        return ResourceType::query()
            ->where('slug', 'dataset')
            ->value('id')
            ?? ResourceType::query()->value('id');
    }

    private function normaliseYear(mixed $year): ?int
    {
        if (! is_numeric($year)) {
            return null;
        }

        $year = (int) $year;

        return $year >= 1000 && $year <= 9999 ? $year : null;
    }

    private function shouldSkipLegacyDoi(string $doi): bool
    {
        return app(LegacyLandingPageDecisionService::class)->shouldSkipLegacyDoi($doi);
    }

    private function normaliseDoi(string $doi): string
    {
        $normalised = $this->doiSuggestionService->normalizeDoi($doi);

        return $normalised !== '' ? $normalised : trim($doi);
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
