<?php

declare(strict_types=1);

namespace App\Enums;

enum PortalCacheArea: string
{
    case PAGE = 'page';
    case COUNT = 'count';
    case RESOURCE_TYPE_FACETS = 'resource-type-facets';
    case DATACENTER_FACETS = 'datacenter-facets';
    case TEMPORAL_RANGE = 'temporal-range';
    case KEYWORDS = 'keywords';
    case MAP_PAYLOAD = 'map-payload';
    case MAP_EXTENT = 'map-extent';

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
