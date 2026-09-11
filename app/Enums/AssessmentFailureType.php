<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentFailureType: string
{
    case RESOURCE = 'resource';
    case SERVICE = 'service';
}
