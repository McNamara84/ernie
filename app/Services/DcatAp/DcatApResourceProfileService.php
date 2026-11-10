<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\Resource;

final class DcatApResourceProfileService
{
    public function isEnabled(): bool
    {
        return (bool) config('dcatap.enabled', false);
    }

    public function supports(Resource $resource): bool
    {
        $resource->loadMissing(['resourceType', 'igsnMetadata']);

        return $this->isEnabled() && $resource->igsnMetadata === null
            && in_array($resource->resourceType?->slug, config('dcatap.resource_types', []), true);
    }

    /** @return list<string> */
    public function requiredRelations(): array
    {
        return [
            'resourceType', 'igsnMetadata', 'language', 'publisher', 'titles.titleType',
            'creators.creatorable', 'contributors.contributorable', 'contributors.contributorTypes',
            'descriptions.descriptionType', 'dates.dateType', 'subjects', 'geoLocations',
            'resourceRights.right', 'relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType',
            'alternateIdentifiers', 'formats', 'landingPage.externalDomain', 'landingPage.ftpFormat',
            'landingPage.ftpSize', 'landingPage.files.format', 'landingPage.files.size',
            'landingPage.links.format', 'landingPage.links.size',
        ];
    }
}
