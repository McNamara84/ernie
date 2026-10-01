<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\LandingPage;
use App\Models\Resource;

/** Resolve digital access from current landing-page configuration without changing curated metadata. */
final class ResourceAccessLevelResolverService
{
    public function __construct(private readonly LandingPageDownloadAvailabilityService $availability) {}

    public function resolve(Resource $resource, ?LandingPage $landingPage = null): ?AccessLevel
    {
        if ($resource->access_level !== null) {
            return $resource->access_level;
        }

        if ($landingPage === null) {
            $resource->loadMissing('landingPage');
            $landingPage = $resource->landingPage;
        }

        return $this->inferFromLandingPage($resource, $landingPage);
    }

    public function inferFromLandingPage(Resource $resource, ?LandingPage $landingPage): ?AccessLevel
    {
        if ($landingPage === null || $landingPage->isExternal()) {
            return null;
        }

        $resource->loadMissing(['resourceType', 'igsnMetadata']);
        if ($resource->isIgsn() || $resource->igsnMetadata !== null) {
            return null;
        }

        return $this->availability->isAvailable($landingPage)
            ? AccessLevel::OPEN
            : AccessLevel::METADATA_ONLY;
    }
}
