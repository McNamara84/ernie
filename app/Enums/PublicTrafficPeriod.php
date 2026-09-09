<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum PublicTrafficPeriod: string
{
    case FOUR_WEEKS = '4w';
    case TWELVE_WEEKS = '12w';
    case FIFTY_TWO_WEEKS = '52w';

    public function weeks(): int
    {
        return match ($this) {
            self::FOUR_WEEKS => 4,
            self::TWELVE_WEEKS => 12,
            self::FIFTY_TWO_WEEKS => 52,
        };
    }

    public function startsAt(CarbonImmutable $endsAt): CarbonImmutable
    {
        return $endsAt->subWeeks($this->weeks());
    }
}
