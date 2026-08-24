<?php

declare(strict_types=1);

namespace App\Enums;

enum DataCiteUrlUpdateItemStatus: string
{
    case PENDING_PREFLIGHT = 'pending_preflight';
    case PENDING_UPDATE = 'pending_update';
    case UPDATED = 'updated';
    case ALREADY_CURRENT = 'already_current';
    case SKIPPED_REMOTE_MISSING = 'skipped_remote_missing';
    case SKIPPED_TARGET_UNREACHABLE = 'skipped_target_unreachable';
    case SKIPPED_NO_LONGER_ELIGIBLE = 'skipped_no_longer_eligible';
    case FAILED = 'failed';

    public function isPending(): bool
    {
        return in_array($this, [self::PENDING_PREFLIGHT, self::PENDING_UPDATE], true);
    }

    public function isSkipped(): bool
    {
        return str_starts_with($this->value, 'skipped_');
    }

    public function isProcessed(): bool
    {
        return ! $this->isPending();
    }
}
