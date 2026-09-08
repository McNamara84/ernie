<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AssessmentRun;
use App\Services\Assessment\AssessmentRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PrepareAssessmentRunSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $runId) {}

    public function handle(AssessmentRunService $runs): void
    {
        $runs->prepareNextSnapshotChunk($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        $run = AssessmentRun::query()->find($this->runId);
        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        app(AssessmentRunService::class)->pause(
            $run,
            'The assessment snapshot preparation failed unexpectedly. Resume the run after checking the logs.',
            $exception?->getMessage(),
        );
    }
}
