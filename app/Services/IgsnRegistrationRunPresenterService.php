<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IgsnRegistrationRunStatus;
use App\Models\IgsnRegistrationItem;
use App\Models\IgsnRegistrationRun;

final class IgsnRegistrationRunPresenterService
{
    /** @return array<string, mixed> */
    public function run(IgsnRegistrationRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'test_mode' => $run->test_mode,
            'datacite_endpoint' => $run->datacite_endpoint,
            'total' => $run->total,
            'processed' => $run->processed,
            'registered' => $run->registered,
            'updated' => $run->updated,
            'failed' => $run->failed,
            'cancelled' => $run->cancelled,
            'pause_reason' => $run->pause_reason,
            'last_error' => $run->last_error,
            'started_at' => $run->started_at?->toIso8601String(),
            'paused_at' => $run->paused_at?->toIso8601String(),
            'cancelled_at' => $run->cancelled_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'can_cancel' => in_array($run->status, [
                IgsnRegistrationRunStatus::PREPARING,
                IgsnRegistrationRunStatus::QUEUED,
                IgsnRegistrationRunStatus::RUNNING,
                IgsnRegistrationRunStatus::PAUSED,
            ], true),
            'can_resume' => $run->status === IgsnRegistrationRunStatus::PAUSED,
            'can_retry_failed' => $run->failed > 0 && in_array($run->status, [
                IgsnRegistrationRunStatus::COMPLETED,
                IgsnRegistrationRunStatus::FAILED,
                IgsnRegistrationRunStatus::CANCELLED,
            ], true),
        ];
    }

    /** @return array<string, mixed> */
    public function item(IgsnRegistrationItem $item): array
    {
        return [
            'id' => $item->id,
            'resource_id' => $item->resource_id,
            'identifier' => $item->identifier,
            'status' => $item->status->value,
            'operation' => $item->operation,
            'attempts' => $item->attempts,
            'last_http_status' => $item->last_http_status,
            'error_message' => $item->error_message,
            'processed_at' => $item->processed_at?->toIso8601String(),
        ];
    }
}
