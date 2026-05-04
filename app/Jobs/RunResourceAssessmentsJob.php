<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\ResourceCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunResourceAssessmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RESOURCE_SCOPE = 'resource';

    public const IGSN_SCOPE = 'igsn';

    /**
     * @var list<string>
     */
    public const SCOPES = [
        self::RESOURCE_SCOPE,
        self::IGSN_SCOPE,
    ];

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        private readonly string $scope,
        private readonly string $jobId,
        private readonly ?string $lockOwner = null,
        private readonly ?string $lockKey = null,
    ) {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('Assessment scope is invalid.');
        }

        if (! Str::isUuid($jobId)) {
            throw new \InvalidArgumentException('Job ID must be a valid UUID');
        }
    }

    public static function getCacheKey(string $scope, string $jobId): string
    {
        return "resource_assessment:{$scope}:{$jobId}";
    }

    public function handle(FujiAssessmentService $fujiService, ResourceCacheService $resourceCache): void
    {
        $cacheKey = self::getCacheKey($this->scope, $this->jobId);
        $startedAt = now()->toIso8601String();
        $physicalObjectTypeId = $resourceCache->getPhysicalObjectTypeId();

        $query = $this->buildScopeQuery($physicalObjectTypeId)->with('landingPage');
        $totalResources = (clone $query)->count();

        $processedResources = 0;
        $assessedResources = 0;
        $failedResources = 0;
        $skippedResources = 0;

        $this->writeStatus(
            cacheKey: $cacheKey,
            status: 'running',
            progress: $this->progressMessage($processedResources, $totalResources),
            startedAt: $startedAt,
            totalResources: $totalResources,
            processedResources: $processedResources,
            assessedResources: $assessedResources,
            failedResources: $failedResources,
            skippedResources: $skippedResources,
        );

        try {
            foreach ($query->lazyById(100) as $resource) {
                $processedResources++;

                try {
                    $skipReason = $this->resolveSkipReason($resource);

                    if ($skipReason !== null) {
                        ResourceAssessment::query()->updateOrCreate(
                            ['resource_id' => $resource->id],
                            [
                                'status' => ResourceAssessment::STATUS_SKIPPED,
                                'total_score' => null,
                                'assessed_identifier' => $resource->doi,
                                'error_message' => $skipReason,
                                'payload' => null,
                                'assessed_at' => now(),
                            ],
                        );

                        $skippedResources++;
                    } else {
                        $result = $fujiService->assessIdentifier((string) $resource->doi);

                        ResourceAssessment::query()->updateOrCreate(
                            ['resource_id' => $resource->id],
                            [
                                'status' => ResourceAssessment::STATUS_COMPLETED,
                                'total_score' => $result['score'],
                                'assessed_identifier' => $resource->doi,
                                'error_message' => null,
                                'payload' => $result['payload'],
                                'assessed_at' => now(),
                            ],
                        );

                        $assessedResources++;
                    }
                } catch (\Throwable $exception) {
                    ResourceAssessment::query()->updateOrCreate(
                        ['resource_id' => $resource->id],
                        [
                            'status' => ResourceAssessment::STATUS_FAILED,
                            'total_score' => null,
                            'assessed_identifier' => $resource->doi,
                            'error_message' => $exception->getMessage(),
                            'payload' => null,
                            'assessed_at' => now(),
                        ],
                    );

                    $failedResources++;

                    Log::error('Resource assessment failed', [
                        'scope' => $this->scope,
                        'resource_id' => $resource->id,
                        'doi' => $resource->doi,
                        'error' => $exception->getMessage(),
                    ]);
                }

                $this->writeStatus(
                    cacheKey: $cacheKey,
                    status: 'running',
                    progress: $this->progressMessage($processedResources, $totalResources),
                    startedAt: $startedAt,
                    totalResources: $totalResources,
                    processedResources: $processedResources,
                    assessedResources: $assessedResources,
                    failedResources: $failedResources,
                    skippedResources: $skippedResources,
                );
            }

            $this->writeStatus(
                cacheKey: $cacheKey,
                status: 'completed',
                progress: sprintf('%s assessment completed.', $this->scopeLabel()),
                startedAt: $startedAt,
                totalResources: $totalResources,
                processedResources: $processedResources,
                assessedResources: $assessedResources,
                failedResources: $failedResources,
                skippedResources: $skippedResources,
                completedAt: now()->toIso8601String(),
            );
        } finally {
            $this->releaseLock();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $cacheKey = self::getCacheKey($this->scope, $this->jobId);
        $existing = Cache::get($cacheKey, []);

        Cache::put($cacheKey, [
            ...(is_array($existing) ? $existing : []),
            'status' => 'failed',
            'progress' => sprintf('%s assessment failed.', $this->scopeLabel()),
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completedAt' => now()->toIso8601String(),
        ], now()->addHours(2));

        $this->releaseLock();
    }

    /**
     * @return Builder<Resource>
     */
    private function buildScopeQuery(?int $physicalObjectTypeId): Builder
    {
        $query = Resource::query();

        if ($this->scope === self::IGSN_SCOPE) {
            if ($physicalObjectTypeId === null) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('resource_type_id', $physicalObjectTypeId);
        }

        if ($physicalObjectTypeId === null) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($physicalObjectTypeId): void {
            $builder->whereNull('resource_type_id')
                ->orWhere('resource_type_id', '!=', $physicalObjectTypeId);
        });
    }

    private function resolveSkipReason(Resource $resource): ?string
    {
        if (! is_string($resource->doi) || trim($resource->doi) === '') {
            return 'Resource has no DOI.';
        }

        if ($resource->landingPage === null) {
            return 'Resource has no landing page.';
        }

        if (! $resource->landingPage->isPublished()) {
            return 'Landing page is not published.';
        }

        return null;
    }

    private function progressMessage(int $processedResources, int $totalResources): string
    {
        if ($totalResources === 0) {
            return sprintf('No %s found for assessment.', strtolower($this->scopeLabel()));
        }

        return sprintf('Assessing %s %d of %d...', strtolower($this->scopeLabel()), $processedResources, $totalResources);
    }

    private function scopeLabel(): string
    {
        return $this->scope === self::IGSN_SCOPE ? 'IGSNs' : 'Resources';
    }

    private function releaseLock(): void
    {
        if ($this->lockOwner !== null && $this->lockKey !== null) {
            Cache::restoreLock($this->lockKey, $this->lockOwner)->release();
        }
    }

    private function writeStatus(
        string $cacheKey,
        string $status,
        string $progress,
        string $startedAt,
        int $totalResources,
        int $processedResources,
        int $assessedResources,
        int $failedResources,
        int $skippedResources,
        ?string $completedAt = null,
    ): void {
        Cache::put($cacheKey, array_filter([
            'status' => $status,
            'progress' => $progress,
            'totalResources' => $totalResources,
            'processedResources' => $processedResources,
            'assessedResources' => $assessedResources,
            'failedResources' => $failedResources,
            'skippedResources' => $skippedResources,
            'startedAt' => $startedAt,
            'completedAt' => $completedAt,
        ], static fn (mixed $value): bool => $value !== null), now()->addHours(2));
    }
}