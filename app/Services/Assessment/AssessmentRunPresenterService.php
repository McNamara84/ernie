<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\AssessmentRunStatus;
use App\Models\AssessmentRun;

final class AssessmentRunPresenterService
{
    /** @return array<string, mixed> */
    public function present(AssessmentRun $run): array
    {
        return [
            'jobId' => $run->id,
            'scope' => $run->scope->value,
            'status' => $run->status->value,
            'progress' => $this->progress($run),
            'error' => $run->last_error ?? $run->pause_reason,
            'totalResources' => $run->total,
            'processedResources' => $run->processed,
            'assessedResources' => $run->assessed,
            'failedResources' => $run->failed,
            'skippedResources' => $run->skipped,
            'pendingResources' => $run->pending,
            'startedAt' => $run->started_at?->toIso8601String(),
            'pausedAt' => $run->paused_at?->toIso8601String(),
            'completedAt' => $run->completed_at?->toIso8601String(),
            'updatedAt' => $run->updated_at->toIso8601String(),
        ];
    }

    private function progress(AssessmentRun $run): string
    {
        $label = $run->scope->label();

        return match ($run->status) {
            AssessmentRunStatus::PREPARING => "{$label} assessment is being prepared.",
            AssessmentRunStatus::QUEUED => "{$label} assessment is waiting to start.",
            AssessmentRunStatus::RUNNING => sprintf('Assessing %s %d of %d...', strtolower($label), $run->processed, $run->total),
            AssessmentRunStatus::PAUSED => "{$label} assessment paused.",
            AssessmentRunStatus::CANCEL_REQUESTED => "{$label} assessment cancellation requested.",
            AssessmentRunStatus::CANCELLED => "{$label} assessment cancelled.",
            AssessmentRunStatus::COMPLETED => "{$label} assessment completed.",
            AssessmentRunStatus::FAILED => "{$label} assessment failed.",
        };
    }
}
