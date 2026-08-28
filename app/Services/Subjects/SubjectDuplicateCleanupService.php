<?php

declare(strict_types=1);

namespace App\Services\Subjects;

use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Subject;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\PortalKeywordCacheInvalidationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SubjectDuplicateCleanupService
{
    private const string ASSISTANT_TARGET_TYPE = 'subject';

    /**
     * @var list<string>
     */
    private const array IDENTITY_FIELDS = [
        'value',
        'language',
        'subject_scheme',
        'scheme_uri',
        'value_uri',
        'classification_code',
        'breadcrumb_path',
    ];

    public function __construct(
        private readonly PortalKeywordCacheInvalidationService $keywordCache,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @param  list<string>  $schemes
     * @return array{
     *     resources_scanned: int,
     *     subjects_scanned: int,
     *     duplicate_groups: int,
     *     duplicate_subjects: int,
     *     assistant_rows: int,
     *     unchanged_resources: int,
     *     errors: int,
     *     records: list<array{
     *         resource_id: int,
     *         doi: string,
     *         scheme: string,
     *         survivor_id: int,
     *         duplicate_ids: string,
     *         group_size: int,
     *         status: string,
     *         message: string
     *     }>
     * }
     */
    public function run(
        bool $apply = false,
        int $afterResourceId = 0,
        int $limit = 0,
        int $chunk = 250,
        array $dois = [],
        array $schemes = [],
        bool $includeFree = false,
    ): array {
        $result = [
            'resources_scanned' => 0,
            'subjects_scanned' => 0,
            'duplicate_groups' => 0,
            'duplicate_subjects' => 0,
            'assistant_rows' => 0,
            'unchanged_resources' => 0,
            'errors' => 0,
            'records' => [],
        ];
        $changedResourceIds = [];
        $normalizedSchemes = $this->normalizeFilters($schemes);

        $query = Resource::query()
            ->select(['resources.id', 'resources.doi'])
            ->where('resources.id', '>', max(0, $afterResourceId))
            ->whereHas('subjects', function (Builder $query) use ($normalizedSchemes, $includeFree): void {
                if ($normalizedSchemes !== []) {
                    $query->whereIn('subject_scheme', $normalizedSchemes);

                    return;
                }

                if (! $includeFree) {
                    $query->whereNotNull('subject_scheme')
                        ->where('subject_scheme', '!=', '');
                }
            });

        $normalizedDois = $this->normalizeDois($dois);
        if ($normalizedDois !== []) {
            $query->whereIn(DB::raw('LOWER(doi)'), $normalizedDois);
        }

        foreach ($query->lazyById(max(1, min(1000, $chunk)), 'resources.id', 'id') as $resource) {
            if ($limit > 0 && $result['resources_scanned'] >= $limit) {
                break;
            }

            $result['resources_scanned']++;

            try {
                $analysis = $apply
                    ? DB::transaction(fn (): array => $this->analyzeResource(
                        (int) $resource->id,
                        $normalizedSchemes,
                        $includeFree,
                        true,
                    ))
                    : $this->analyzeResource(
                        (int) $resource->id,
                        $normalizedSchemes,
                        $includeFree,
                        false,
                    );

                $result['subjects_scanned'] += $analysis['subjects_scanned'];
                $result['duplicate_groups'] += count($analysis['groups']);
                $result['duplicate_subjects'] += $analysis['duplicate_subjects'];
                $result['assistant_rows'] += $analysis['assistant_rows'];

                if ($analysis['groups'] === []) {
                    $result['unchanged_resources']++;

                    continue;
                }

                foreach ($analysis['groups'] as $group) {
                    $result['records'][] = [
                        'resource_id' => (int) $resource->id,
                        'doi' => (string) ($resource->doi ?? ''),
                        'scheme' => $group['scheme'],
                        'survivor_id' => $group['survivor_id'],
                        'duplicate_ids' => implode(',', $group['duplicate_ids']),
                        'group_size' => count($group['duplicate_ids']) + 1,
                        'status' => $apply ? 'deleted' : 'would_delete',
                        'message' => $apply
                            ? 'Exact duplicate subjects deleted.'
                            : 'Dry run; no data was changed.',
                    ];
                }

                if ($apply && $analysis['duplicate_subjects'] > 0) {
                    $changedResourceIds[] = (int) $resource->id;
                }
            } catch (Throwable $exception) {
                report($exception);
                $result['errors']++;
                $result['records'][] = [
                    'resource_id' => (int) $resource->id,
                    'doi' => (string) ($resource->doi ?? ''),
                    'scheme' => '',
                    'survivor_id' => 0,
                    'duplicate_ids' => '',
                    'group_size' => 0,
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($changedResourceIds !== []) {
            try {
                $this->keywordCache->scheduleAfterCommit();
                $this->invalidateLandingPageCaches($changedResourceIds);
            } catch (Throwable $exception) {
                report($exception);
                $result['errors']++;
                $result['records'][] = [
                    'resource_id' => 0,
                    'doi' => '',
                    'scheme' => '',
                    'survivor_id' => 0,
                    'duplicate_ids' => '',
                    'group_size' => 0,
                    'status' => 'cache_error',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $schemes
     * @return array{
     *     subjects_scanned: int,
     *     duplicate_subjects: int,
     *     assistant_rows: int,
     *     groups: list<array{scheme: string, survivor_id: int, duplicate_ids: list<int>}>
     * }
     */
    private function analyzeResource(
        int $resourceId,
        array $schemes,
        bool $includeFree,
        bool $delete,
    ): array {
        $query = Subject::query()
            ->select(array_merge(['id', 'resource_id'], self::IDENTITY_FIELDS))
            ->where('resource_id', $resourceId);

        $this->applySubjectScope($query, $schemes, $includeFree);

        if ($delete) {
            $query->lockForUpdate();
        }

        /** @var Collection<int, Subject> $subjects */
        $subjects = $query->orderBy('id')->get();
        $groups = $this->duplicateGroups($subjects);
        $duplicateIds = array_merge(...array_map(
            static fn (array $group): array => $group['duplicate_ids'],
            $groups,
        ));
        $assistantRows = 0;

        if ($delete && $duplicateIds !== []) {
            $assistantRows += AssistantSuggestion::query()
                ->where('target_type', self::ASSISTANT_TARGET_TYPE)
                ->whereIn('target_id', $duplicateIds)
                ->delete();
            $assistantRows += AssistantDismissed::query()
                ->where('target_type', self::ASSISTANT_TARGET_TYPE)
                ->whereIn('target_id', $duplicateIds)
                ->delete();

            Subject::query()->whereIn('id', $duplicateIds)->delete();
        }

        return [
            'subjects_scanned' => $subjects->count(),
            'duplicate_subjects' => count($duplicateIds),
            'assistant_rows' => $assistantRows,
            'groups' => $groups,
        ];
    }

    /**
     * @param  Collection<int, Subject>  $subjects
     * @return list<array{scheme: string, survivor_id: int, duplicate_ids: list<int>}>
     */
    private function duplicateGroups(Collection $subjects): array
    {
        /** @var array<string, list<Subject>> $grouped */
        $grouped = [];

        foreach ($subjects as $subject) {
            $grouped[$this->identityFingerprint($subject)][] = $subject;
        }

        $duplicates = [];
        foreach ($grouped as $group) {
            if (count($group) < 2) {
                continue;
            }

            $survivor = array_shift($group);
            $duplicates[] = [
                'scheme' => (string) ($survivor->subject_scheme ?? ''),
                'survivor_id' => (int) $survivor->id,
                'duplicate_ids' => array_map(static fn (Subject $subject): int => (int) $subject->id, $group),
            ];
        }

        return $duplicates;
    }

    private function identityFingerprint(Subject $subject): string
    {
        return hash('sha256', json_encode(array_map(
            static fn (string $field): mixed => $subject->getRawOriginal($field),
            self::IDENTITY_FIELDS,
        ), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Builder<Subject>  $query
     * @param  list<string>  $schemes
     * @return Builder<Subject>
     */
    private function applySubjectScope(Builder $query, array $schemes, bool $includeFree): Builder
    {
        if ($schemes !== []) {
            return $query->whereIn('subject_scheme', $schemes);
        }

        if ($includeFree) {
            return $query;
        }

        return $query->controlled();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeFilters(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $values,
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param  list<string>  $dois
     * @return list<string>
     */
    private function normalizeDois(array $dois): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (string $doi): string {
                $normalized = trim($doi);
                $normalized = preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', $normalized) ?? $normalized;
                $normalized = preg_replace('/^doi:\s*/i', '', $normalized) ?? $normalized;

                return strtolower(trim($normalized));
            },
            $dois,
        ))));
    }

    /**
     * @param  list<int>  $resourceIds
     */
    private function invalidateLandingPageCaches(array $resourceIds): void
    {
        $igsnResourceIds = array_values(IgsnMetadata::query()
            ->whereIn('resource_id', $resourceIds)
            ->pluck('resource_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());

        if ($igsnResourceIds !== []) {
            $this->landingPageCache->forgetForIgsnFamilies($igsnResourceIds);
        }

        $regularResourceIds = array_values(array_diff($resourceIds, $igsnResourceIds));
        if ($regularResourceIds === []) {
            return;
        }

        $landingPageIds = LandingPage::query()
            ->whereIn('resource_id', $regularResourceIds)
            ->pluck('id')
            ->all();

        foreach ($landingPageIds as $landingPageId) {
            $this->landingPageCache->forgetById((int) $landingPageId);
        }
    }
}
