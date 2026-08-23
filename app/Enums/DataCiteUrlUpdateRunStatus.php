<?php

declare(strict_types=1);

namespace App\Enums;

enum DataCiteUrlUpdateRunStatus: string
{
    case PREPARING = 'preparing';
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case PAUSED = 'paused';
    case CANCEL_REQUESTED = 'cancel_requested';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [
            self::PREPARING,
            self::QUEUED,
            self::RUNNING,
            self::PAUSED,
            self::CANCEL_REQUESTED,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::COMPLETED, self::FAILED], true);
    }
}
