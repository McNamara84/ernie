<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataCiteUrlUpdateItem;
use App\Models\DataCiteUrlUpdateRun;

class DataCiteUrlUpdateRunPresenter
{
    /** @return array<string, mixed> */
    public function run(DataCiteUrlUpdateRun $run): array
    {
        return [
            'id' => $run->id,
            'scope' => $run->scope->value,
            'scope_label' => $run->scope->label(),
            'status' => $run->status->value,
            'test_mode' => $run->test_mode,
            'datacite_endpoint' => $run->datacite_endpoint,
            'target_base_url' => $run->target_base_url,
            'total' => $run->total,
            'processed' => $run->processed,
            'updated' => $run->updated,
            'already_current' => $run->already_current,
            'skipped' => $run->skipped,
            'failed' => $run->failed,
            'pause_reason' => $run->pause_reason,
            'last_error' => $run->last_error,
            'started_at' => $run->started_at?->toIso8601String(),
            'paused_at' => $run->paused_at?->toIso8601String(),
            'cancelled_at' => $run->cancelled_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'can_cancel' => in_array($run->status->value, ['preparing', 'queued', 'running', 'paused'], true),
            'can_resume' => in_array($run->status->value, ['paused', 'cancelled'], true),
            'can_retry_failed' => $run->failed > 0 && in_array($run->status->value, ['completed', 'failed', 'cancelled'], true),
        ];
    }

    /** @return array<string, mixed> */
    public function item(DataCiteUrlUpdateItem $item): array
    {
        return [
            'id' => $item->id,
            'resource_id' => $item->resource_id,
            'identifier' => $item->identifier,
            'status' => $item->status->value,
            'before_url' => $item->before_url,
            'target_url' => $item->target_url,
            'datacite_state' => $item->datacite_state,
            'attempts' => $item->preflight_attempts + $item->update_attempts,
            'last_http_status' => $item->last_http_status,
            'error_message' => $item->error_message,
            'processed_at' => $item->processed_at?->toIso8601String(),
        ];
    }
}
