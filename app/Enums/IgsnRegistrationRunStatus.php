<?php

declare(strict_types=1);

namespace App\Enums;

enum IgsnRegistrationRunStatus: string
{
    case PREPARING = 'preparing';
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case PAUSED = 'paused';
    case CANCEL_REQUESTED = 'cancel_requested';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /** @return list<self> */
    public static function activeCases(): array
    {
        return [
            self::PREPARING,
            self::QUEUED,
            self::RUNNING,
            self::PAUSED,
            self::CANCEL_REQUESTED,
        ];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::activeCases());
    }

    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::COMPLETED, self::FAILED], true);
    }
}
