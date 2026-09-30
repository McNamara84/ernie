<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalScope;
use Illuminate\Filesystem\Filesystem;

/**
 * Joins repository-managed editorial content to the DOI portal's public selection.
 * The public contract stays independent of a future database-backed content source.
 *
 * @phpstan-type DataCentre array{
 *     slug: string, datacenterName: string, displayName: string, shortName: string,
 *     logo: array{src: string, width: int, height: int}|null,
 *     description: list<string>, links: list<array{label: string, href: string}>
 * }
 */
final class DataCentreCatalogService
{
    public function __construct(
        private readonly PortalSearchService $search,
        private readonly Filesystem $files,
    ) {}

    /** @return list<DataCentre> */
    public function published(): array
    {
        /** @var list<DataCentre> $entries */
        $entries = $this->files->json(resource_path('data/data-centres.json'), JSON_THROW_ON_ERROR);
        $byName = array_column($entries, null, 'datacenterName');

        return array_map(function (array $facet) use ($byName): array {
            $entry = $byName[$facet['name']] ?? $this->fallback($facet['name']);

            // Keep editorial provenance private and the Inertia contract explicit.
            return [
                'slug' => $entry['slug'],
                'datacenterName' => $facet['name'],
                'displayName' => $entry['displayName'],
                'shortName' => $entry['shortName'],
                'logo' => $entry['logo'],
                'description' => $entry['description'],
                'links' => $entry['links'],
            ];
        }, array_values($this->search->getDatacenterFacets(PortalScope::DOI)));
    }

    /** @return DataCentre */
    private function fallback(string $name): array
    {
        return [
            // Reserve this namespace in the editorial catalogue. Unlike slugifying
            // names, hashing also distinguishes punctuation and non-Latin names.
            'slug' => 'datacenter-'.hash('sha256', $name),
            'datacenterName' => $name,
            'displayName' => $name,
            'shortName' => $name,
            'logo' => null,
            'description' => ['Explore the publications assigned to this data centre in the GFZ Data Services Data Portal.'],
            'links' => [],
        ];
    }
}
