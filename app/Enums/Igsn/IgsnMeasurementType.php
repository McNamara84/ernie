<?php

declare(strict_types=1);

namespace App\Enums\Igsn;

enum IgsnMeasurementType: string
{
    case TotalLength = 'total_length';
    case AgeRange = 'age_range';
    case ElevationRange = 'elevation_range';
}
