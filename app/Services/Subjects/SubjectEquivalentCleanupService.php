<?php

declare(strict_types=1);

namespace App\Services\Subjects;

use App\Enums\CacheKey;
use App\Enums\PortalCacheArea;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Subject;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\OaiPmh\OaiPmhDatestampService;
use App\Services\PortalCacheInvalidationService;
use App\Services\ResourceCacheService;
use App\Support\PortalSubjectNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Reviewed, snapshot-bound repair; the legacy exact cleanup retains its contract. */
final readonly class SubjectEquivalentCleanupService
{
    public function __construct(private SubjectEquivalenceService $equivalence) {}

    /** @param list<string> $dois
     * @param  list<string>  $schemes
     * @return array<string, mixed>
     */
    public function audit(array $dois = [], array $schemes = [], bool $includeFree = false, int $after = 0, int $limit = 0, int $chunk = 250): array
    {
        $query = Resource::query()->where('id', '>', $after)->whereHas('subjects');
        $dois = array_values(array_filter(array_map(static function (string $doi): string {
            return strtolower(trim(preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', trim($doi)) ?? $doi));
        }, $dois)));
        if ($dois !== []) {
            $query->whereIn(DB::raw('LOWER(doi)'), $dois);
        }
        $records = [];
        $scanned = 0;
        $lastResourceId = $after;
        foreach ($query->lazyById(max(1, min(1000, $chunk))) as $resource) {
            if ($limit > 0 && $scanned >= $limit) {
                break;
            }
            $scanned++;
            $lastResourceId = $resource->id;
            $record = $this->inspect($resource, $schemes, $includeFree);
            if ($record['analysis']['groups'] !== [] || $record['analysis']['diagnostics'] !== []) {
                $records[] = $record;
            }
        }

        return ['version' => SubjectEquivalenceService::VERSION, 'mode' => 'equivalent', 'resources_scanned' => $scanned,
            'last_resource_id' => $lastResourceId, 'resources' => $records];
    }

    /** @param list<string> $schemes
     * @return array<string, mixed>
     */
    private function inspect(Resource $resource, array $schemes, bool $includeFree, bool $lock = false): array
    {
        $query = $resource->subjects()->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $all = $query->get()->map(static fn (Subject $subject): array => $subject->getAttributes())->all();
        $normalizedSchemes = array_map(PortalSubjectNormalizer::normalizeScheme(...), $schemes);
        $subjects = array_values(array_filter($all, static function (array $row) use ($normalizedSchemes, $includeFree): bool {
            $scheme = PortalSubjectNormalizer::normalizeScheme($row['subject_scheme']);

            return ($includeFree || $scheme !== null) && ($normalizedSchemes === [] || in_array($scheme, $normalizedSchemes, true));
        }));
        $analysis = $this->equivalence->analyze($subjects);
        $snapshot = ['resource_id' => $resource->id, 'doi' => $resource->doi, 'schemes' => $schemes, 'include_free' => $includeFree,
            'subjects' => $subjects, 'all_subjects' => $all, 'analysis' => $analysis];

        return [...$snapshot, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    /** @param array<string, mixed> $report
     * @param  callable(array<string, mixed>): void  $journal  Must durably record intent before any mutation.
     * @return list<array{resource_id: int, status: string, message: string}>
     */
    public function apply(array $report, callable $journal, bool $refreshOnly = false): array
    {
        $this->validateReport($report);
        $results = [];
        foreach ($report['resources'] as $record) {
            $resourceId = $record['resource_id'];
            try {
                if ($refreshOnly) {
                    $status = 'caches_refreshed';
                } else {
                    $status = DB::transaction(function () use ($record, $resourceId, $journal): string {
                        $resource = Resource::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
                        if ($resource->embargo_registration_started_at !== null) {
                            throw new RuntimeException('An embargo registration is pending reconciliation.');
                        }
                        $current = $this->inspect($resource, $record['schemes'], $record['include_free'], true);
                        if (! hash_equals($current['fingerprint'], $record['fingerprint'])) {
                            throw new RuntimeException('Subjects or vocabulary evidence changed; create and review a new dry run.');
                        }
                        $groups = $current['analysis']['groups'];
                        if ($groups === []) {
                            return 'unchanged';
                        }
                        $subjects = $current['subjects'];
                        $affected = $removed = $affectedSchemes = [];
                        foreach ($groups as $group) {
                            foreach ($group['indexes'] as $index) {
                                $affected[] = $subjects[$index]['id'];
                                $affectedSchemes[] = PortalSubjectNormalizer::normalizeScheme($subjects[$index]['subject_scheme']);
                                if ($index !== $group['survivor']) {
                                    $removed[] = $subjects[$index]['id'];
                                }
                            }
                        }
                        $suggestions = AssistantSuggestion::query()->where(function ($q) use ($affected, $resourceId): void {
                            $q->where(fn ($q) => $q->where('target_type', 'subject')->whereIn('target_id', $affected))
                                ->orWhere(fn ($q) => $q->where('resource_id', $resourceId)->where('assistant_id', 'subject-hierarchy-correction'));
                        })->orderBy('id')->lockForUpdate()->get()->filter(static fn (AssistantSuggestion $suggestion): bool => $suggestion->target_type === 'subject'
                            || in_array(PortalSubjectNormalizer::normalizeScheme($suggestion->metadata['scheme'] ?? null), $affectedSchemes, true));
                        $hierarchyValues = $suggestions->where('assistant_id', 'subject-hierarchy-correction')->pluck('suggested_value')->all();
                        $dismissals = AssistantDismissed::query()->where(function ($q) use ($affected, $resourceId, $hierarchyValues): void {
                            $q->where(fn ($q) => $q->where('target_type', 'subject')->whereIn('target_id', $affected))
                                ->orWhere(fn ($q) => $q->where('target_type', 'subject_hierarchy')->where('target_id', $resourceId)
                                    ->where('assistant_id', 'subject-hierarchy-correction')->whereIn('dismissed_value', $hierarchyValues));
                        })->orderBy('id')->lockForUpdate()->get();
                        $journal(['status' => 'before', 'snapshot' => $current,
                            'suggestions' => $suggestions->toArray(), 'dismissals' => $dismissals->toArray()]);
                        foreach ($groups as $group) {
                            Subject::query()->whereKey($subjects[$group['survivor']]['id'])
                                ->update(SubjectEquivalenceService::fields($group['row']));
                        }
                        Subject::query()->whereIn('id', $removed)->delete();
                        AssistantSuggestion::query()->whereKey($suggestions->modelKeys())->delete();
                        AssistantDismissed::query()->whereKey($dismissals->modelKeys())->delete();
                        app(OaiPmhDatestampService::class)->touchResource($resourceId);

                        return 'applied';
                    });
                }
                // Cache recovery after a committed repair uses the explicit refresh-only path.
                try {
                    $this->refreshCaches($resourceId);
                    $result = ['resource_id' => $resourceId, 'status' => $status, 'message' => ''];
                } catch (Throwable $exception) {
                    report($exception);
                    $result = ['resource_id' => $resourceId, 'status' => 'cache_error', 'message' => $exception->getMessage()];
                }
            } catch (Throwable $exception) {
                report($exception);
                $result = ['resource_id' => $resourceId, 'status' => 'error', 'message' => $exception->getMessage()];
            }
            $journal($result);
            $results[] = $result;
        }

        return $results;
    }

    private function refreshCaches(int $resourceId): void
    {
        CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->forget();
        CacheKey::ASSISTANCE_DATACENTER_OPTIONS->forget();
        app(ResourceCacheService::class)->invalidateResourceCache($resourceId);
        app(PortalCacheInvalidationService::class)->scheduleForResourceId($resourceId, [PortalCacheArea::PAGE, PortalCacheArea::COUNT,
            PortalCacheArea::KEYWORDS, PortalCacheArea::IGSN_FACETS, PortalCacheArea::MAP_PAYLOAD, PortalCacheArea::MAP_EXTENT]);
        $cache = app(LandingPageRenderDataCacheService::class);
        if (IgsnMetadata::query()->where('resource_id', $resourceId)->exists()) {
            $cache->forgetForIgsnFamilies([$resourceId]);
        } else {
            foreach (LandingPage::query()->where('resource_id', $resourceId)->pluck('id') as $id) {
                $cache->forgetById((int) $id);
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function validateReport(array $report): void
    {
        if (($report['version'] ?? null) !== SubjectEquivalenceService::VERSION || ($report['mode'] ?? null) !== 'equivalent'
            || ! is_array($report['resources'] ?? null) || ! array_is_list($report['resources'])) {
            throw new InvalidArgumentException('Invalid or unsupported equivalent-subject report.');
        }
        $seen = [];
        foreach ($report['resources'] as $row) {
            if (! is_array($row) || ! is_int($row['resource_id'] ?? null) || $row['resource_id'] < 1
                || isset($seen[$row['resource_id']]) || ! is_string($row['fingerprint'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $row['fingerprint']) || ! is_bool($row['include_free'] ?? null)
                || ! is_array($row['schemes'] ?? null) || ! array_is_list($row['schemes'])
                || array_any($row['schemes'], static fn (mixed $scheme): bool => ! is_string($scheme))) {
                throw new InvalidArgumentException('Invalid resource snapshot in equivalent-subject report.');
            }
            $snapshot = $row;
            unset($snapshot['fingerprint']);
            if (! hash_equals($row['fingerprint'], hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)))) {
                throw new InvalidArgumentException('The reviewed resource snapshot was modified. Create a new dry run.');
            }
            $seen[$row['resource_id']] = true;
        }
    }
}
