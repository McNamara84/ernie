<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SchemaOrgProfile;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Support\SchemaOrgResourceType;

final class SchemaOrgResourceTypeMappingService
{
    public function resolve(Resource $resource): SchemaOrgResourceType
    {
        $resource->loadMissing('resourceType');

        return $this->resolveSlug($resource->resourceType?->slug);
    }

    public function resolveSlug(?string $slug): SchemaOrgResourceType
    {
        /** @var array<string, array{primary_type: string, additional_types: list<string>, profile: string, fallback_reason: string|null}> $mappings */
        $mappings = config('schemaorg.resource_types');
        $known = $slug !== null && isset($mappings[$slug]);

        /** @var array{primary_type: string, additional_types: list<string>, profile: string, fallback_reason: string|null} $mapping */
        $mapping = $known ? $mappings[$slug] : config('schemaorg.fallback');

        return new SchemaOrgResourceType(
            $mapping['primary_type'],
            $mapping['additional_types'],
            SchemaOrgProfile::from($mapping['profile']),
            $mapping['fallback_reason'],
            $known && $mapping['fallback_reason'] !== null
                ? ResourceType::slugToDataciteResourceTypeGeneral($slug)
                : null,
        );
    }
}
