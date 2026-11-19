<?php

declare(strict_types=1);

namespace App\Enums;

enum NewsKind: string
{
    case REGULAR = 'regular';
    case TEMPORARY = 'temporary';
}
