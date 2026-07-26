<?php

declare(strict_types=1);

namespace App\Services\Iso19115;

use App\Models\GeoLocation;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Defines the deliberately selective ERNIE profile for ISO 19115-3.
 *
 * Resource-type slugs are immutable database identifiers. Display labels must
 * never be used here because they can be translated or renamed.
 */
class Iso19115ResourceProfileService
{
    /**
     * @return array<string, string>
     */
    public function resourceScopes(): array
    {
        /** @var array<string, string> $scopes */
        $scopes = config('iso19115.resource_scopes', []);

        return $scopes;
    }

    /**
     * @return list<string>
     */
    public function eligibleResourceTypeSlugs(): array
    {
        return array_keys($this->resourceScopes());
    }

    public function isEnabled(): bool
    {
        return (bool) config('iso19115.enabled', true);
    }

    public function supports(Resource $resource): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $resource->loadMissing('resourceType');
        $slug = $resource->resourceType?->slug;

        return $slug !== null && array_key_exists($slug, $this->resourceScopes());
    }

    public function supportsDeletedRecord(OaiPmhDeletedRecord $record): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        foreach ($record->sets ?? [] as $setSpec) {
            if (! str_starts_with($setSpec, 'resourcetype:')) {
                continue;
            }

            $slug = substr($setSpec, strlen('resourcetype:'));

            if (array_key_exists($slug, $this->resourceScopes())) {
                return true;
            }
        }

        return false;
    }

    public function scopeCode(Resource $resource): ?string
    {
        if (! $this->supports($resource)) {
            return null;
        }

        $slug = $resource->resourceType?->slug;
        if ($slug === null) {
            return null;
        }

        // A georeferenced image represents coverage; other images are documents.
        if ($slug === 'image') {
            $resource->loadMissing('geoLocations');

            $hasUsableGeography = $resource->geoLocations->contains(
                static fn (GeoLocation $geoLocation): bool => $geoLocation->hasPlace()
                    || $geoLocation->hasPoint()
                    || $geoLocation->hasBox()
                    || $geoLocation->hasPolygon()
                    || $geoLocation->hasLine(),
            );

            return $hasUsableGeography ? 'coverage' : 'document';
        }

        return $this->resourceScopes()[$slug] ?? null;
    }

    /**
     * Restrict an OAI-PMH live-resource query to ISO-compatible resource types.
     *
     * @param  Builder<Resource>  $query
     * @return Builder<Resource>
     */
    public function applyToResourceQuery(Builder $query): Builder
    {
        if (! $this->isEnabled()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'resourceType',
            fn (Builder $typeQuery): Builder => $typeQuery->whereIn('slug', $this->eligibleResourceTypeSlugs()),
        );
    }

    /**
     * Restrict an OAI-PMH deleted-record query using its persisted type set.
     *
     * @param  Builder<OaiPmhDeletedRecord>  $query
     * @return Builder<OaiPmhDeletedRecord>
     */
    public function applyToDeletedRecordQuery(Builder $query): Builder
    {
        if (! $this->isEnabled()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $eligibleQuery): void {
            foreach ($this->eligibleResourceTypeSlugs() as $index => $slug) {
                if ($index === 0) {
                    $eligibleQuery->whereJsonContains('sets', "resourcetype:{$slug}");
                } else {
                    $eligibleQuery->orWhereJsonContains('sets', "resourcetype:{$slug}");
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    public function requiredRelations(): array
    {
        return [
            'resourceType',
            'language',
            'publisher',
            'titles.titleType',
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorTypes',
            'contributors.affiliations',
            'descriptions.descriptionType',
            'dates.dateType',
            'subjects',
            'geoLocations',
            'resourceRights.right',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'alternateIdentifiers',
            'formats',
            'sizes',
            'igsnMetadata.parentResource.titles.titleType',
            'igsnClassifications',
            'landingPage.files',
            'landingPage.links',
        ];
    }
}
