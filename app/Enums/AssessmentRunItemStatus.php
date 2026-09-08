<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentRunItemStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case ASSESSED = 'assessed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::PENDING->value, self::QUEUED->value, self::PROCESSING->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
