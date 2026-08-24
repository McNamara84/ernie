<?php

declare(strict_types=1);

namespace App\Enums;

enum DataCiteUrlUpdateScope: string
{
    case RESOURCES = 'resources';
    case IGSNS = 'igsns';

    public function label(): string
    {
        return match ($this) {
            self::RESOURCES => 'Resources',
            self::IGSNS => 'IGSNs',
        };
    }
}
