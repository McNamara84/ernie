<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AssessmentRunItemStatus;
use App\Enums\AssessmentRunStatus;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Services\Assessment\AssessmentQueueService;
use App\Services\Assessment\AssessmentRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchAssessmentRunItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $runId) {}

    public function handle(AssessmentRunService $runs, AssessmentQueueService $queue): void
    {
        /** @var list<int> $itemIds */
        $itemIds = DB::transaction(function () use ($runs): array {
            $run = AssessmentRun::query()->lockForUpdate()->find($this->runId);

            if ($run === null
                || $run->status->isTerminal()
                || in_array($run->status, [AssessmentRunStatus::PREPARING, AssessmentRunStatus::PAUSED], true)) {
                return [];
            }

            if ($run->status === AssessmentRunStatus::CANCEL_REQUESTED) {
                AssessmentRunItem::query()
                    ->where('run_id', $run->id)
                    ->whereIn('status', AssessmentRunItemStatus::openValues())
                    ->update([
                        'status' => AssessmentRunItemStatus::CANCELLED,
                        'processing_started_at' => null,
                        'lease_expires_at' => null,
                        'processed_at' => now(),
                    ]);
                $runs->recalculate($run);
                $run->refresh();
                $run->forceFill([
                    'status' => AssessmentRunStatus::CANCELLED,
                    'active_scope' => null,
                    'cancelled_at' => now(),
                    'completed_at' => now(),
                ])->save();
                Log::info('Resource assessment run cancelled', $this->runLogContext($run));

                return [];
            }

            if (! $runs->configurationMatches($run)) {
                $runs->pause($run, 'F-UJI configuration changed while the assessment run was active.');

                return [];
            }

            AssessmentRunItem::query()
                ->where('run_id', $run->id)
                ->whereIn('status', [AssessmentRunItemStatus::QUEUED, AssessmentRunItemStatus::PROCESSING])
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<=', now())
                ->update([
                    'status' => AssessmentRunItemStatus::PENDING,
                    'available_at' => null,
                    'processing_started_at' => null,
                    'lease_expires_at' => null,
                ]);

            $inFlight = AssessmentRunItem::query()
                ->where('run_id', $run->id)
                ->whereIn('status', [AssessmentRunItemStatus::QUEUED, AssessmentRunItemStatus::PROCESSING])
                ->count();
            $slots = max(0, $run->concurrency - $inFlight);
            $items = $slots === 0
                ? new Collection
                : AssessmentRunItem::query()
                    ->where('run_id', $run->id)
                    ->where('status', AssessmentRunItemStatus::PENDING)
                    ->where(function ($query): void {
                        $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit($slots)
                    ->get();

            $leaseExpiresAt = now()->addSeconds(max(30, (int) config('fuji.assessment.lease_seconds', 210)));
            foreach ($items as $item) {
                $item->forceFill([
                    'status' => AssessmentRunItemStatus::QUEUED,
                    'lease_expires_at' => $leaseExpiresAt,
                ])->save();
            }

            if ($run->status !== AssessmentRunStatus::RUNNING && ($items->isNotEmpty() || $inFlight > 0)) {
                $run->forceFill([
                    'status' => AssessmentRunStatus::RUNNING,
                    'started_at' => $run->started_at ?? now(),
                ])->save();
            }

            $hasOpenItems = AssessmentRunItem::query()
                ->where('run_id', $run->id)
                ->whereIn('status', AssessmentRunItemStatus::openValues())
                ->exists();

            if (! $hasOpenItems) {
                $runs->recalculate($run);
                $run->refresh();
                $run->forceFill([
                    'status' => AssessmentRunStatus::COMPLETED,
                    'active_scope' => null,
                    'completed_at' => now(),
                ])->save();
                Log::info('Resource assessment run completed', $this->runLogContext($run));
            }

            return $items->modelKeys();
        }, 3);

        foreach ($itemIds as $itemId) {
            AssessResourceRunItemJob::dispatch($itemId)
                ->onConnection($queue->connection())
                ->onQueue($queue->queue())
                ->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = AssessmentRun::query()->find($this->runId);
        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        app(AssessmentRunService::class)->pause(
            $run,
            'The assessment dispatcher failed unexpectedly. Resume the run after checking the logs.',
            $exception?->getMessage(),
        );
    }

    /** @return array<string, int|float|string|null> */
    private function runLogContext(AssessmentRun $run): array
    {
        $durationSeconds = $run->started_at?->diffInSeconds(now());

        return [
            'run_id' => $run->id,
            'scope' => $run->scope->value,
            'total' => $run->total,
            'processed' => $run->processed,
            'assessed' => $run->assessed,
            'failed' => $run->failed,
            'skipped' => $run->skipped,
            'pending' => $run->pending,
            'duration_seconds' => $durationSeconds,
            'throughput_per_minute' => $durationSeconds === null || $durationSeconds <= 0
                ? null
                : round(($run->processed / $durationSeconds) * 60, 2),
        ];
    }
}
