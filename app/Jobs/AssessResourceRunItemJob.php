<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AssessmentFailureType;
use App\Enums\AssessmentRunItemStatus;
use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
use App\Exceptions\FujiAssessmentException;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Services\Assessment\AssessmentRunService;
use App\Services\Assessment\FujiAssessmentRequestLimiterService;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\ResourceCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssessResourceRunItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TRANSACTION_ATTEMPTS = 3;

    public int $tries = 1;

    public int $timeout = 330;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $itemId)
    {
        $this->timeout = max(30, (int) config('fuji.assessment.item_timeout_seconds', 330));
    }

    public function handle(
        FujiAssessmentService $fuji,
        FujiAssessmentRequestLimiterService $limiter,
        ResourceCacheService $resourceCache,
        AssessmentRunService $runs,
    ): void {
        $item = $this->claim();
        if ($item === null) {
            return;
        }

        $run = $item->run;
        $resource = $item->resource;
        $skipReason = $this->skipReason($run, $resource, $resourceCache);
        if ($skipReason !== null) {
            $this->finish($item, AssessmentRunItemStatus::SKIPPED, $resource, $resourceCache, error: $skipReason);
            $runs->dispatch($run);

            return;
        }

        assert($resource !== null);
        $identifier = trim((string) $resource->doi);
        $item->forceFill(['identifier' => $identifier])->save();

        $waitMs = $limiter->reserveSlot();
        if ($waitMs > 0) {
            $delay = (int) ceil($waitMs / 1000);
            $this->defer($item, $delay);
            $this->logDeferred($run, $item, $delay, 'shared_rate_limit');
            $runs->dispatch($run, $delay);

            return;
        }

        $item->increment('attempts');
        $item->refresh();
        $started = microtime(true);

        try {
            $result = $fuji->assessIdentifier($identifier);
        } catch (FujiAssessmentException $exception) {
            if ($exception->httpStatus === 429) {
                $limiter->imposeCooldown($exception->retryAfterSeconds ?? 60);
            }

            if ($exception->retryable && $item->attempts < max(1, (int) config('fuji.assessment.max_attempts', 3))) {
                $delay = $this->retryDelay($item->attempts, $exception->retryAfterSeconds);
                $this->defer($item, $delay, $exception);
                $this->logDeferred($run, $item, $delay, 'retryable_fuji_failure', $exception->httpStatus, $exception);
                $runs->dispatch($run, $delay);

                return;
            }

            $this->finish(
                $item,
                AssessmentRunItemStatus::FAILED,
                $resource,
                $resourceCache,
                error: $exception->getMessage(),
                httpStatus: $exception->httpStatus,
                failureType: $exception->failureType,
                errorCode: $exception->errorCode,
                errorDetail: $exception->errorDetail,
                durationMs: $exception->durationMs ?? $this->durationMs($started),
                expectedIdentifier: $identifier,
            );
            $item->refresh();
            if ($item->status->isTerminal()) {
                $this->logFinished($run, $item, $item->status->value, $started, $exception);
            }
            $runs->dispatch($run);

            return;
        }

        $this->finish(
            $item,
            AssessmentRunItemStatus::ASSESSED,
            $resource,
            $resourceCache,
            $result,
            httpStatus: 200,
            durationMs: $this->durationMs($started),
            expectedIdentifier: $identifier,
        );
        $item->refresh();
        if ($item->status->isTerminal()) {
            $this->logFinished($run, $item, $item->status->value, $started, httpStatus: 200);
        }
        $runs->dispatch($run);
    }

    public function failed(?Throwable $exception): void
    {
        $runId = AssessmentRunItem::query()->whereKey($this->itemId)->value('run_id');
        if (! is_string($runId)) {
            return;
        }

        DB::transaction(function () use ($exception, $runId): void {
            $run = AssessmentRun::query()->lockForUpdate()->find($runId);
            $item = AssessmentRunItem::query()->lockForUpdate()->find($this->itemId);
            if ($run === null || $item === null || $item->status->isTerminal()) {
                return;
            }

            $item->forceFill([
                'status' => AssessmentRunItemStatus::PENDING,
                'available_at' => null,
                'processing_started_at' => null,
                'lease_expires_at' => null,
                'error_message' => $this->sanitize($exception?->getMessage() ?? 'Unknown queue failure.'),
            ])->save();

            if (! $run->status->isTerminal()) {
                app(AssessmentRunService::class)->pause(
                    $run,
                    'The assessment worker failed unexpectedly. Resume the run after checking the logs.',
                    $exception?->getMessage(),
                );
            }
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function claim(): ?AssessmentRunItem
    {
        return DB::transaction(function (): ?AssessmentRunItem {
            $item = AssessmentRunItem::query()->with('run')->lockForUpdate()->find($this->itemId);
            if ($item === null || $item->status !== AssessmentRunItemStatus::QUEUED) {
                return null;
            }

            if ($item->run->status !== AssessmentRunStatus::RUNNING) {
                return null;
            }

            $item->forceFill([
                'status' => AssessmentRunItemStatus::PROCESSING,
                'processing_started_at' => now(),
                'lease_expires_at' => now()->addSeconds(max($this->timeout + 30, (int) config('fuji.assessment.lease_seconds', 210))),
            ])->save();

            return $item->load(['run', 'resource']);
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function skipReason(AssessmentRun $run, ?Resource $resource, ResourceCacheService $resourceCache): ?string
    {
        if ($resource === null) {
            return 'Resource no longer exists.';
        }

        if (! is_string($resource->doi) || trim($resource->doi) === '') {
            return 'Resource has no DOI.';
        }

        $physicalObjectTypeId = $resourceCache->getPhysicalObjectTypeId();
        $matches = $run->scope === AssessmentScope::IGSN
            ? $physicalObjectTypeId !== null && $resource->resource_type_id === $physicalObjectTypeId
            : $physicalObjectTypeId === null || $resource->resource_type_id !== $physicalObjectTypeId;

        return $matches ? null : 'Resource no longer belongs to this assessment scope.';
    }

    /** @param array{score: float, payload: array<string, mixed>}|null $result */
    private function finish(
        AssessmentRunItem $item,
        AssessmentRunItemStatus $status,
        ?Resource $resource,
        ResourceCacheService $resourceCache,
        ?array $result = null,
        ?string $error = null,
        ?int $httpStatus = null,
        ?AssessmentFailureType $failureType = null,
        ?string $errorCode = null,
        ?string $errorDetail = null,
        ?int $durationMs = null,
        ?string $expectedIdentifier = null,
    ): void {
        $runId = AssessmentRunItem::query()->whereKey($item->id)->value('run_id');
        if (! is_string($runId)) {
            return;
        }

        DB::transaction(function () use ($runId, $item, $status, $resource, $resourceCache, $result, $error, $httpStatus, $failureType, $errorCode, $errorDetail, $durationMs, $expectedIdentifier): void {
            $run = AssessmentRun::query()->lockForUpdate()->find($runId);
            if ($run === null) {
                return;
            }

            $lockedItem = AssessmentRunItem::query()->lockForUpdate()->find($item->id);
            if ($lockedItem === null || $lockedItem->status !== AssessmentRunItemStatus::PROCESSING) {
                return;
            }

            $currentResource = $resource === null ? null : Resource::query()->lockForUpdate()->find($resource->id);
            $currentSkipReason = $this->skipReason($run, $currentResource, $resourceCache);

            if ($currentSkipReason === null && $currentResource !== null && $expectedIdentifier !== null) {
                $currentIdentifier = trim((string) $currentResource->doi);

                if ($currentIdentifier !== $expectedIdentifier) {
                    $lockedItem->forceFill([
                        'status' => AssessmentRunItemStatus::PENDING,
                        'identifier' => $currentIdentifier,
                        'last_http_status' => $httpStatus,
                        'failure_type' => null,
                        'error_code' => null,
                        'error_message' => 'Resource DOI changed during assessment; retrying the current DOI.',
                        'error_detail' => null,
                        'last_attempt_duration_ms' => $durationMs,
                        'available_at' => null,
                        'processing_started_at' => null,
                        'lease_expires_at' => null,
                    ])->save();

                    return;
                }
            }

            if ($currentSkipReason !== null) {
                $status = AssessmentRunItemStatus::SKIPPED;
                $result = null;
                $error = $currentSkipReason;
                $httpStatus = null;
                $failureType = null;
                $errorCode = null;
                $errorDetail = null;
            }

            if ($currentResource !== null) {
                ResourceAssessment::query()->updateOrCreate(
                    ['resource_id' => $currentResource->id],
                    [
                        'status' => match ($status) {
                            AssessmentRunItemStatus::ASSESSED => ResourceAssessment::STATUS_COMPLETED,
                            AssessmentRunItemStatus::FAILED => ResourceAssessment::STATUS_FAILED,
                            default => ResourceAssessment::STATUS_SKIPPED,
                        },
                        'failure_type' => $status === AssessmentRunItemStatus::FAILED ? $failureType : null,
                        'error_code' => $status === AssessmentRunItemStatus::FAILED ? $errorCode : null,
                        'total_score' => $result['score'] ?? null,
                        'assessed_identifier' => $currentResource->doi,
                        'error_message' => $error === null ? null : $this->sanitize($error),
                        'payload' => $result['payload'] ?? null,
                        'assessed_at' => now(),
                    ],
                );
            }

            $resolvedIdentifier = $currentResource?->doi;
            $lockedItem->forceFill([
                'status' => $status,
                'identifier' => $resolvedIdentifier ?? $lockedItem->identifier,
                'last_http_status' => $httpStatus,
                'failure_type' => $status === AssessmentRunItemStatus::FAILED ? $failureType : null,
                'error_code' => $status === AssessmentRunItemStatus::FAILED ? $errorCode : null,
                'error_message' => $error === null ? null : $this->sanitize($error),
                'error_detail' => $errorDetail === null ? null : $this->sanitize($errorDetail),
                'last_attempt_duration_ms' => $durationMs,
                'available_at' => null,
                'processing_started_at' => null,
                'lease_expires_at' => null,
                'processed_at' => now(),
            ])->save();

            $run->processed++;
            $run->pending = max(0, $run->pending - 1);

            match ($status) {
                AssessmentRunItemStatus::ASSESSED => $run->assessed++,
                AssessmentRunItemStatus::FAILED => $failureType === AssessmentFailureType::SERVICE
                    ? $run->service_errors++
                    : $run->failed++,
                default => $run->skipped++,
            };

            $run->save();
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function defer(AssessmentRunItem $item, int $delaySeconds, ?Throwable $exception = null): void
    {
        DB::transaction(function () use ($item, $delaySeconds, $exception): void {
            // The dispatcher and terminal item updates lock the run before its
            // items. Use the same order here so parallel deferrals cannot
            // deadlock while moving rows back into the pending index.
            AssessmentRun::query()->lockForUpdate()->find($item->run_id);

            AssessmentRunItem::query()
                ->whereKey($item->id)
                ->where('status', AssessmentRunItemStatus::PROCESSING)
                ->update([
                    'status' => AssessmentRunItemStatus::PENDING,
                    'available_at' => now()->addSeconds(max(1, $delaySeconds)),
                    'processing_started_at' => null,
                    'lease_expires_at' => null,
                    'last_http_status' => $exception instanceof FujiAssessmentException ? $exception->httpStatus : null,
                    'failure_type' => $exception instanceof FujiAssessmentException ? $exception->failureType : null,
                    'error_code' => $exception instanceof FujiAssessmentException ? $exception->errorCode : null,
                    'error_message' => $exception === null ? null : $this->sanitize($exception->getMessage()),
                    'error_detail' => $exception instanceof FujiAssessmentException && $exception->errorDetail !== null
                        ? $this->sanitize($exception->errorDetail)
                        : null,
                    'last_attempt_duration_ms' => $exception instanceof FujiAssessmentException ? $exception->durationMs : null,
                ]);
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function retryDelay(int $attempts, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null) {
            return max(1, $retryAfterSeconds);
        }

        $base = max(1, (int) config('fuji.assessment.retry_base_seconds', 15));
        $jitter = max(0, (int) config('fuji.assessment.retry_jitter_seconds', 5));

        return ($base * (2 ** max(0, $attempts - 1))) + ($jitter === 0 ? 0 : random_int(0, $jitter));
    }

    private function logFinished(
        AssessmentRun $run,
        AssessmentRunItem $item,
        string $status,
        float $started,
        ?FujiAssessmentException $exception = null,
        ?int $httpStatus = null,
    ): void
    {
        Log::info('Resource assessment item finished', [
            'run_id' => $run->id,
            'item_id' => $item->id,
            'scope' => $run->scope->value,
            'resource_id' => $item->resource_id,
            'identifier' => $item->identifier,
            'attempt' => $item->attempts,
            'status' => $status,
            'http_status' => $exception?->httpStatus ?? $httpStatus,
            'failure_type' => $exception?->failureType->value,
            'error_code' => $exception?->errorCode,
            'duration_ms' => $exception?->durationMs ?? $this->durationMs($started),
        ]);
    }

    private function logDeferred(
        AssessmentRun $run,
        AssessmentRunItem $item,
        int $delaySeconds,
        string $reason,
        ?int $httpStatus = null,
        ?FujiAssessmentException $exception = null,
    ): void {
        Log::info('Resource assessment item deferred', [
            'run_id' => $run->id,
            'item_id' => $item->id,
            'scope' => $run->scope->value,
            'resource_id' => $item->resource_id,
            'identifier' => $item->identifier,
            'attempt' => $item->attempts,
            'reason' => $reason,
            'http_status' => $httpStatus,
            'failure_type' => $exception?->failureType->value,
            'error_code' => $exception?->errorCode,
            'duration_ms' => $exception?->durationMs,
            'delay_seconds' => $delaySeconds,
        ]);
    }

    private function sanitize(string $message): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 1000);
    }

    private function durationMs(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }
}
