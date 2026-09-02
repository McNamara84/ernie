<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum LogDownloadPeriod: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';

    public function startsAt(CarbonImmutable $endsAt): CarbonImmutable
    {
        return match ($this) {
            self::DAY => $endsAt->subHours(24),
            self::WEEK => $endsAt->subDays(7),
            self::MONTH => $endsAt->subDays(30),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DAY => 'Last 24 hours',
            self::WEEK => 'Last 7 days',
            self::MONTH => 'Last 30 days',
        };
    }

    public function filenameSegment(): string
    {
        return match ($this) {
            self::DAY => 'last-24-hours',
            self::WEEK => 'last-7-days',
            self::MONTH => 'last-30-days',
        };
    }
}
