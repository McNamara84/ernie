<?php

declare(strict_types=1);

namespace App\Support\GeoLocations;

use App\Models\GeoLocation;

final readonly class GeoLocationWriteResult
{
    public function __construct(public GeoLocation $location, public bool $duplicate) {}
}
