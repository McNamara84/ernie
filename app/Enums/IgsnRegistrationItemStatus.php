<?php

declare(strict_types=1);

namespace App\Enums;

enum IgsnRegistrationItemStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case REGISTERED = 'registered';
    case UPDATED = 'updated';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this, [self::REGISTERED, self::UPDATED], true);
    }

    public function isProcessed(): bool
    {
        return ! $this->isPending();
    }
}
