<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Exceptions\ConcurrentLegacyCreatorAndMslMetadataChangeException;
use App\Exceptions\LegacyBackfillRecordConsumerException;
use App\Models\Institution;
use App\Models\OldDataset;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Subject;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\Creators\ResourceCreatorNameResolverService;
use App\Services\DataCiteCreatorNameMergeService;
use App\Services\DoiSuggestionService;
use App\Services\LegacyCreatorNameService;
use App\Services\LegacyKeywordService;
use App\Support\LegacyMslScheme;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LegacyCreatorAndMslMetadataBackfillService
{
    private const LEGACY_SOURCE = 'sumario-pmd';

    public function __construct(
        private readonly DoiSuggestionService $doiSuggestionService,
        private readonly LegacyCreatorNameService $legacyCreatorNames,
        private readonly LegacyKeywordService $legacyKeywords,
        private readonly DataCiteCreatorNameMergeService $creatorMerger,
        private readonly ResourceCreatorNameResolverService $creatorNameResolver,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @param  list<int>  $legacyIds
     * @param  (callable(array<string, mixed>): void)|null  $recordConsumer
     * @param  bool  $retainRecords  Retain emitted records only for explicitly bounded callers.
     * @return array<string, mixed>
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        int $chunk = 100,
        array $dois = [],
        array $legacyIds = [],
        bool $matchByDoi = false,
        ?callable $recordConsumer = null,
        bool $retainRecords = false,
    ): array {
        $this->preflightLegacyDatabase();

        $cursor = max(0, $afterId);
        $limit = max(0, $limit);
        $chunk = max(1, min(1000, $chunk));
        $doiFilter = $this->normalizeDoiFilter($dois);
        $legacyIdFilter = array_values(array_unique(array_filter(
            array_map('intval', $legacyIds),
            static fn (int $id): bool => $id > 0,
        )));
        $stats = $this->emptyStats();

        if (($dois !== [] && $doiFilter === []) || ($legacyIds !== [] && $legacyIdFilter === [])) {
            return $stats;
        }

        while ($limit === 0 || $stats['scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['scanned']);
            $resources = Resource::query()
                ->with(['creators.creatorable', 'subjects', 'landingPage'])
                ->whereKeyNot(0)
                ->where('id', '>', $cursor)
                ->when(
                    ! $matchByDoi,
                    fn (Builder $query): Builder => $query
                        ->where('legacy_source', self::LEGACY_SOURCE)
                        ->whereNotNull('legacy_source_id'),
                    fn (Builder $query): Builder => $query->where(function (Builder $scope): void {
                        $scope->where(function (Builder $linked): void {
                            $linked->where('legacy_source', self::LEGACY_SOURCE)
                                ->whereNotNull('legacy_source_id');
                        })->orWhereNotNull('doi');
                    }),
                )
                ->when($doiFilter !== [], fn (Builder $query): Builder => $query->whereIn('doi', $doiFilter))
                ->when($legacyIdFilter !== [], fn (Builder $query): Builder => $query->whereIn('legacy_source_id', $legacyIdFilter))
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            foreach ($resources as $resource) {
                $cursor = (int) $resource->id;
                $stats['last_scanned_resource_id'] = $cursor;
                $stats['scanned']++;
                $originalMetadataFingerprint = $this->resourceMetadataFingerprint($resource);

                try {
                    [$oldDataset, $matchMethod] = $this->resolveLegacyResource($resource, $matchByDoi);
                    if ($oldDataset === null) {
                        $stats['missing_legacy']++;
                        $this->emitRecord($stats, $this->record(
                            $resource,
                            $resource->legacy_source_id,
                            $matchMethod,
                            'missing_legacy',
                            message: 'No unique SUMARIO resource was found.',
                        ), $recordConsumer, $retainRecords);

                        continue;
                    }

                    $legacyCreators = $this->legacyCreatorNames->dataCiteCreators($oldDataset);
                    $legacyMslKeywords = array_values(array_filter(
                        $this->legacyKeywords->controlledKeywords($oldDataset),
                        static fn (array $keyword): bool => LegacyMslScheme::isSupported(
                            is_string($keyword['scheme'] ?? null) ? $keyword['scheme'] : null,
                        ),
                    ));

                    $result = $apply
                        ? DB::transaction(function () use ($resource, $legacyCreators, $legacyMslKeywords, $originalMetadataFingerprint): array {
                            $locked = Resource::query()->lockForUpdate()->findOrFail($resource->id);
                            $this->lockMetadataRelations($locked);
                            if (! hash_equals($originalMetadataFingerprint, $this->resourceMetadataFingerprint($locked))) {
                                throw new ConcurrentLegacyCreatorAndMslMetadataChangeException(
                                    'Creator or subject metadata changed concurrently; the resource was not modified.',
                                );
                            }

                            return $this->backfillResource($locked, $legacyCreators, $legacyMslKeywords, true);
                        })
                        : $this->backfillResource($resource, $legacyCreators, $legacyMslKeywords, false);

                    $hasChanges = $result['creator_snapshots_written'] > 0
                        || $result['subjects_created'] > 0
                        || $result['subjects_enriched'] > 0;
                    $hasVisibleChanges = $result['visible_creator_changes'] > 0
                        || $result['subjects_created'] > 0
                        || $result['subjects_enriched'] > 0;

                    if ($hasChanges) {
                        $stats['changed']++;
                    } else {
                        $stats['unchanged']++;
                    }
                    if ($result['warnings'] !== []) {
                        $stats['manual_review']++;
                    }

                    foreach (['creator_snapshots_written', 'visible_creator_changes', 'subjects_created', 'subjects_enriched', 'subject_conflicts'] as $key) {
                        $stats[$key] += $result[$key];
                    }

                    if ($apply && $hasVisibleChanges) {
                        $result['cache_invalidation_failed'] = false;
                        try {
                            if ($resource->landingPage?->isPublished() === true
                                && ! $this->landingPageCache->forgetById((int) $resource->landingPage->id)
                            ) {
                                throw new RuntimeException('The cache store returned false.');
                            }
                        } catch (\Throwable $exception) {
                            $stats['cache_invalidation_failures']++;
                            $result['cache_invalidation_failed'] = true;
                            $result['warnings'][] = 'Landing-page cache invalidation failed: '.$exception->getMessage();
                            Log::warning('Legacy creator and MSL backfill remains applied despite landing-page cache invalidation failure', [
                                'resource_id' => $resource->id,
                                'landing_page_id' => $resource->landingPage->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }

                        if ($resource->doi !== null && trim($resource->doi) !== '') {
                            $stats['sync_resource_ids'][] = (int) $resource->id;
                        }
                    }

                    $status = match (true) {
                        $hasChanges && $apply && $result['warnings'] !== [] => 'updated_with_warnings',
                        $hasChanges && $apply => 'updated',
                        $hasChanges && $result['warnings'] !== [] => 'would_update_with_warnings',
                        $hasChanges => 'would_update',
                        $result['warnings'] !== [] => 'manual_review',
                        default => 'unchanged',
                    };
                    $this->emitRecord($stats, $this->record(
                        $resource,
                        (int) $oldDataset->id,
                        $matchMethod,
                        $status,
                        $result,
                        implode(' ', $result['warnings']),
                    ), $recordConsumer, $retainRecords);
                } catch (LegacyBackfillRecordConsumerException $exception) {
                    throw $exception;
                } catch (ConcurrentLegacyCreatorAndMslMetadataChangeException $exception) {
                    $stats['concurrent_changes']++;
                    $this->emitRecord($stats, $this->record(
                        $resource,
                        $resource->legacy_source_id,
                        $resource->legacy_source_id !== null ? 'legacy_source_id' : 'doi',
                        'concurrent_change',
                        message: $exception->getMessage(),
                    ), $recordConsumer, $retainRecords);
                } catch (RuntimeException $exception) {
                    $stats['manual_review']++;
                    $this->emitRecord($stats, $this->record(
                        $resource,
                        $resource->legacy_source_id,
                        $resource->legacy_source_id !== null ? 'legacy_source_id' : 'doi',
                        'manual_review',
                        message: $exception->getMessage(),
                    ), $recordConsumer, $retainRecords);
                } catch (\Throwable $exception) {
                    $stats['errors']++;
                    $this->emitRecord($stats, $this->record(
                        $resource,
                        $resource->legacy_source_id,
                        $resource->legacy_source_id !== null ? 'legacy_source_id' : 'doi',
                        'error',
                        message: $exception->getMessage(),
                    ), $recordConsumer, $retainRecords);
                    Log::warning('Legacy creator and MSL metadata backfill failed', [
                        'resource_id' => $resource->id,
                        'legacy_resource_id' => $resource->legacy_source_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $stats['sync_resource_ids'] = array_values(array_unique($stats['sync_resource_ids']));

        return $stats;
    }

    private function preflightLegacyDatabase(): void
    {
        DB::connection((new OldDataset)->getConnectionName())
            ->table((new OldDataset)->getTable())
            ->exists();
    }

    /**
     * Lock mutable resource metadata in one deterministic order before checking
     * the scan fingerprint. On MySQL, the indexed resource_id ranges also guard
     * against matching inserts until the transaction commits.
     */
    private function lockMetadataRelations(Resource $resource): void
    {
        $creators = ResourceCreator::query()
            ->where('resource_id', $resource->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $creators->load('creatorable');

        $subjects = Subject::query()
            ->where('resource_id', $resource->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $resource->setRelation(
            'creators',
            $creators->sortBy([
                ['position', 'asc'],
                ['id', 'asc'],
            ])->values(),
        );
        $resource->setRelation('subjects', $subjects);
        $resource->load('landingPage');
    }

    /** @return array{0: OldDataset|null, 1: string} */
    private function resolveLegacyResource(Resource $resource, bool $matchByDoi): array
    {
        if ($resource->legacy_source === self::LEGACY_SOURCE && $resource->legacy_source_id !== null) {
            return [OldDataset::query()->find($resource->legacy_source_id), 'legacy_source_id'];
        }

        if (! $matchByDoi || $resource->doi === null || trim($resource->doi) === '') {
            return [null, 'none'];
        }

        $doi = $this->doiSuggestionService->normalizeDoi($resource->doi);
        $matches = OldDataset::query()
            ->whereRaw('LOWER(identifier) = ?', [mb_strtolower($doi)])
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException('Multiple SUMARIO resources have the same DOI; no automatic match is safe.');
        }

        return [$matches->first(), 'doi'];
    }

    /**
     * @param  list<array<string, mixed>>  $legacyCreators
     * @param  list<array<string, string|bool|null>>  $legacyMslKeywords
     * @return array<string, mixed>
     */
    private function backfillResource(Resource $resource, array $legacyCreators, array $legacyMslKeywords, bool $apply): array
    {
        $creatorResult = $this->backfillCreators($resource, $legacyCreators, $apply);
        $subjectResult = $this->backfillSubjects($resource, $legacyMslKeywords, $apply);

        return [
            'legacy_creators' => count($legacyCreators),
            'ernie_creators' => $resource->creators->count(),
            'legacy_msl_subjects' => count($legacyMslKeywords),
            ...$creatorResult,
            ...$subjectResult,
            'warnings' => [...$creatorResult['warnings'], ...$subjectResult['warnings']],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $legacyCreators
     * @return array<string, mixed>
     */
    private function backfillCreators(Resource $resource, array $legacyCreators, bool $apply): array
    {
        $current = [];
        $rows = [];

        foreach ($resource->creators->values() as $index => $author) {
            $entity = $author->creatorable;
            if ($entity instanceof Person) {
                $name = $this->creatorNameResolver->resolve($author, $entity);
                $creator = [
                    'name' => $name['name'],
                    'nameType' => 'Personal',
                    'givenName' => $name['given_name'],
                    'familyName' => $name['family_name'],
                ];
                if ($entity->hasOrcid()) {
                    $creator['nameIdentifiers'] = [[
                        'nameIdentifier' => $entity->name_identifier,
                        'nameIdentifierScheme' => 'ORCID',
                    ]];
                }
                $current[] = $creator;
                $rows[$index] = $author;
            } else {
                /** @var Institution $entity */
                $current[] = ['name' => $entity->name, 'nameType' => 'Organizational'];
            }
        }

        $merge = $this->creatorMerger->mergeWithReport($current, $legacyCreators);
        $written = 0;
        $visibleChanges = 0;
        $warnings = [];

        foreach ($merge['matches'] as $match) {
            $row = $rows[$match['current_index']] ?? null;
            if (! $row instanceof ResourceCreator) {
                continue;
            }
            if (! in_array($match['status'], ['merged', 'identical'], true) || $match['legacy_index'] === null) {
                if (in_array($match['status'], ['ambiguous', 'not_richer'], true)) {
                    $warnings[] = "Creator position {$row->position} was not changed ({$match['status']}).";
                }

                continue;
            }

            $legacy = $legacyCreators[$match['legacy_index']];
            $snapshot = [
                'name_snapshot' => $this->filled($legacy['name'] ?? null),
                'given_name_snapshot' => $this->filled($legacy['givenName'] ?? null),
                'family_name_snapshot' => $this->filled($legacy['familyName'] ?? null),
            ];
            if ($snapshot['name_snapshot'] === null
                && $snapshot['given_name_snapshot'] === null
                && $snapshot['family_name_snapshot'] === null
            ) {
                continue;
            }

            if ($row->hasNameSnapshot()) {
                $existing = $row->only(array_keys($snapshot));
                if ($existing !== $snapshot) {
                    $warnings[] = "Creator position {$row->position} already has a different resource-specific name snapshot.";
                }

                continue;
            }

            $person = $row->creatorable;
            if (! $person instanceof Person) {
                continue;
            }
            $before = $this->creatorNameResolver->resolve($row, $person);
            $visible = $before['name'] !== ($snapshot['name_snapshot'] ?? '')
                || $before['given_name'] !== $snapshot['given_name_snapshot']
                || $before['family_name'] !== $snapshot['family_name_snapshot'];
            $written++;
            $visibleChanges += $visible ? 1 : 0;

            if ($apply) {
                $row->fill($snapshot)->save();
            }
        }

        return [
            'creator_snapshots_written' => $written,
            'visible_creator_changes' => $visibleChanges,
            'creator_match_methods' => implode('|', array_map(
                static fn (array $match): string => $match['method'].':'.$match['status'],
                $merge['matches'],
            )),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, string|bool|null>>  $legacyMslKeywords
     * @return array<string, mixed>
     */
    private function backfillSubjects(Resource $resource, array $legacyMslKeywords, bool $apply): array
    {
        $created = 0;
        $enriched = 0;
        $conflicts = 0;
        $warnings = [];
        $seen = [];

        foreach ($legacyMslKeywords as $keyword) {
            $scheme = $this->filled($keyword['scheme'] ?? null);
            $path = PortalSubjectNormalizer::normalizeControlledSubjectValue(
                $this->filled($keyword['path'] ?? null),
            );
            if (! LegacyMslScheme::isSupported($scheme) || $path === null) {
                continue;
            }

            $identity = mb_strtolower(LegacyMslScheme::CANONICAL_SCHEME.'|'.$path);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $matches = $resource->subjects->filter(function (Subject $subject) use ($path): bool {
                if (PortalSubjectNormalizer::normalizeScheme($subject->subject_scheme) !== LegacyMslScheme::CANONICAL_SCHEME) {
                    return false;
                }

                $currentPath = SubjectBreadcrumbPath::preferredPath($subject->breadcrumb_path, $subject->value)
                    ?? $subject->value;

                return mb_strtolower((string) PortalSubjectNormalizer::normalizeControlledSubjectValue($currentPath))
                    === mb_strtolower($path);
            })->values();

            if ($matches->count() > 1) {
                $conflicts++;
                $warnings[] = "Legacy MSL subject '{$path}' matches multiple ERNIE subjects.";

                continue;
            }

            $sourceValueUri = filter_var($keyword['id'] ?? null, FILTER_VALIDATE_URL)
                ? trim((string) $keyword['id'])
                : null;
            $sourceSchemeUri = filter_var($keyword['schemeURI'] ?? null, FILTER_VALIDATE_URL)
                ? trim((string) $keyword['schemeURI'])
                : null;
            /** @var Subject|null $subject */
            $subject = $matches->first();

            if ($subject === null) {
                $created++;
                if ($apply) {
                    $createdSubject = Subject::create([
                        'resource_id' => $resource->id,
                        'value' => $path,
                        'language' => $this->filled($keyword['language'] ?? null) ?? 'en',
                        'subject_scheme' => $scheme,
                        'scheme_uri' => $sourceSchemeUri,
                        'value_uri' => $sourceValueUri,
                        'breadcrumb_path' => $path,
                    ]);
                    $resource->subjects->push($createdSubject);
                }

                continue;
            }

            $uriConflict = ($sourceValueUri !== null && $subject->value_uri !== null && $subject->value_uri !== $sourceValueUri)
                || ($sourceSchemeUri !== null && $subject->scheme_uri !== null && $subject->scheme_uri !== $sourceSchemeUri);
            if ($uriConflict) {
                $conflicts++;
                $warnings[] = "Legacy MSL subject '{$path}' has conflicting non-empty URI metadata.";

                continue;
            }

            $updates = [];
            if ($subject->breadcrumb_path === null) {
                $updates['breadcrumb_path'] = $path;
            }
            if ($subject->value_uri === null && $sourceValueUri !== null) {
                $updates['value_uri'] = $sourceValueUri;
            }
            if ($subject->scheme_uri === null && $sourceSchemeUri !== null) {
                $updates['scheme_uri'] = $sourceSchemeUri;
            }
            if ($updates !== []) {
                $enriched++;
                if ($apply) {
                    $subject->fill($updates)->save();
                }
            }
        }

        return [
            'subjects_created' => $created,
            'subjects_enriched' => $enriched,
            'subject_conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<string>  $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = $this->doiSuggestionService->normalizeDoi($doi);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    private function filled(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function resourceMetadataFingerprint(Resource $resource): string
    {
        $creators = $resource->creators
            ->map(static fn (ResourceCreator $creator): array => [
                'id' => (int) $creator->id,
                'creatorable_type' => $creator->creatorable_type,
                'creatorable_id' => (int) $creator->creatorable_id,
                'position' => (int) $creator->position,
                'name_snapshot' => $creator->name_snapshot,
                'given_name_snapshot' => $creator->given_name_snapshot,
                'family_name_snapshot' => $creator->family_name_snapshot,
                'updated_at' => $creator->updated_at?->toJSON(),
            ])
            ->sortBy('id')
            ->values()
            ->all();
        $subjects = $resource->subjects
            ->map(static fn (Subject $subject): array => [
                'id' => (int) $subject->id,
                'value' => $subject->value,
                'language' => $subject->language,
                'subject_scheme' => $subject->subject_scheme,
                'scheme_uri' => $subject->scheme_uri,
                'value_uri' => $subject->value_uri,
                'classification_code' => $subject->classification_code,
                'breadcrumb_path' => $subject->breadcrumb_path,
                'updated_at' => $subject->updated_at?->toJSON(),
            ])
            ->sortBy('id')
            ->values()
            ->all();

        return hash('sha256', json_encode([$creators, $subjects], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function emptyStats(): array
    {
        return [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'missing_legacy' => 0,
            'manual_review' => 0,
            'concurrent_changes' => 0,
            'errors' => 0,
            'creator_snapshots_written' => 0,
            'visible_creator_changes' => 0,
            'subjects_created' => 0,
            'subjects_enriched' => 0,
            'subject_conflicts' => 0,
            'cache_invalidation_failures' => 0,
            'last_scanned_resource_id' => null,
            'sync_resource_ids' => [],
            'records' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $record
     * @param  (callable(array<string, mixed>): void)|null  $recordConsumer
     */
    private function emitRecord(
        array &$stats,
        array $record,
        ?callable $recordConsumer,
        bool $retainRecords,
    ): void {
        if ($retainRecords) {
            $stats['records'][] = $record;
        }

        if ($recordConsumer === null) {
            return;
        }

        try {
            $recordConsumer($record);
        } catch (\Throwable $exception) {
            throw new LegacyBackfillRecordConsumerException(
                'Unable to stream a backfill report record: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(
        Resource $resource,
        ?int $legacyResourceId,
        string $matchMethod,
        string $status,
        array $result = [],
        string $message = '',
    ): array {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'legacy_resource_id' => $legacyResourceId,
            'match_method' => $matchMethod,
            'status' => $status,
            'legacy_creators' => (int) ($result['legacy_creators'] ?? 0),
            'ernie_creators' => (int) ($result['ernie_creators'] ?? 0),
            'creator_match_methods' => (string) ($result['creator_match_methods'] ?? ''),
            'creator_snapshots_written' => (int) ($result['creator_snapshots_written'] ?? 0),
            'visible_creator_changes' => (int) ($result['visible_creator_changes'] ?? 0),
            'legacy_msl_subjects' => (int) ($result['legacy_msl_subjects'] ?? 0),
            'subjects_created' => (int) ($result['subjects_created'] ?? 0),
            'subjects_enriched' => (int) ($result['subjects_enriched'] ?? 0),
            'subject_conflicts' => (int) ($result['subject_conflicts'] ?? 0),
            'concurrent_change' => $status === 'concurrent_change' ? 1 : 0,
            'cache_invalidation_failed' => (int) ($result['cache_invalidation_failed'] ?? false),
            'datacite_sync_status' => 'not_requested',
            'message' => $message,
        ];
    }
}
