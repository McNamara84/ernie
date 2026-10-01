<?php

declare(strict_types=1);

namespace App\Enums;

enum TombstoneReason: string
{
    case DATA_LOST = 'data_lost';
    case RETRACTED = 'retracted';
    case LEGAL_RESTRICTION = 'legal_restriction';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DATA_LOST => 'Data lost',
            self::RETRACTED => 'Resource retracted',
            self::LEGAL_RESTRICTION => 'Legal restriction',
            self::OTHER => 'Other reason',
        };
    }
}
