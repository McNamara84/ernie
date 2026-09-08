<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum SystemMetricsPeriod: string
{
    case DAY = 'day';
    case WEEK = 'week';

    public function startsAt(CarbonImmutable $endsAt): CarbonImmutable
    {
        return match ($this) {
            self::DAY => $endsAt->subHours(24),
            self::WEEK => $endsAt->subDays(7),
        };
    }

    public function bucketMinutes(): int
    {
        return match ($this) {
            self::DAY => 5,
            self::WEEK => 30,
        };
    }
}
