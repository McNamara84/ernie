<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Enums\DcatApFormat;
use App\Models\Resource;

final class DcatApResourceProfileService
{
    public function isEnabled(DcatApFormat $format = DcatApFormat::Eu): bool
    {
        return $format->enabled();
    }

    public function supports(Resource $resource, DcatApFormat $format = DcatApFormat::Eu): bool
    {
        $resource->loadMissing(['resourceType', 'igsnMetadata']);

        return $format->enabled() && $resource->igsnMetadata === null
            && in_array($resource->resourceType?->slug, config('dcatap.resource_types', []), true);
    }

    /** @return list<string> */
    public function requiredRelations(DcatApFormat $format = DcatApFormat::Eu): array
    {
        return [
            'resourceType', 'igsnMetadata', 'language', 'publisher', 'titles.titleType',
            'creators.creatorable', 'contributors.contributorable', 'contributors.contributorTypes',
            'descriptions.descriptionType', 'dates.dateType', 'subjects', 'geoLocations',
            'resourceRights.right', 'relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType',
            ...($format === DcatApFormat::Epos ? ['instruments'] : []),
            'alternateIdentifiers', 'formats', 'landingPage.externalDomain', 'landingPage.ftpFormat',
            'landingPage.ftpSize', 'landingPage.files.format', 'landingPage.files.size',
            'landingPage.links.format', 'landingPage.links.size',
        ];
    }
}
