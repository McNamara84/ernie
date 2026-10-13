<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportCancellationResult
{
    case CANCELLED;
    case NOT_FOUND;
    case NOT_RUNNING;
    case UNAVAILABLE;
}
