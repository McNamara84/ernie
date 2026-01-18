<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the IGSN Map page.
 *
 * Displays IGSNs with geolocations on an interactive map.
 */
class IgsnMapController extends Controller
{
    /**
     * Display the IGSN map with all geolocated samples.
     */
    public function index(): Response
    {
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        $query = Resource::query()
            ->with([
                'titles',
                'geoLocations',
                'creators.creatorable',
            ])
            ->whereHas('igsnMetadata')
            ->whereHas('geoLocations', function (Builder $q): void {
                // Only resources with point coordinates
                $q->whereNotNull('point_latitude')
                    ->whereNotNull('point_longitude');
            });

        if ($physicalObjectType !== null) {
            $query->where('resource_type_id', $physicalObjectType->id);
        }

        /** @var \Illuminate\Support\Collection<int, array{id: int, igsn: string|null, title: string, creator: string, publication_year: int|null, geoLocations: \Illuminate\Support\Collection<int, array{id: int, latitude: float, longitude: float, place: string|null}>}> $igsns */
        $igsns = $query->get()->map(function (Resource $resource): array {
            $mainTitle = $resource->titles->first()->value ?? 'Untitled';
            $creator = $resource->creators->first()?->creatorable;
            $creatorName = $creator instanceof Person
                ? trim(($creator->given_name ?? '') . ' ' . ($creator->family_name ?? ''))
                : ($creator->name ?? 'Unknown');

            // Get all valid point geolocations
            $geoLocations = $resource->geoLocations
                ->filter(fn ($geo): bool => $geo->point_latitude !== null && $geo->point_longitude !== null)
                ->map(fn ($geo): array => [
                    'id' => $geo->id,
                    'latitude' => (float) $geo->point_latitude,
                    'longitude' => (float) $geo->point_longitude,
                    'place' => $geo->place,
                ])
                ->values();

            return [
                'id' => $resource->id,
                'igsn' => $resource->doi,
                'title' => $mainTitle,
                'creator' => $creatorName,
                'publication_year' => $resource->publication_year,
                'geoLocations' => $geoLocations,
            ];
        });

        return Inertia::render('igsns/map', [
            'igsns' => $igsns,
        ]);
    }
}
