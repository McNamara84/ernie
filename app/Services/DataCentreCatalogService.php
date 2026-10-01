<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalScope;
use App\Models\Datacenter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

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
        $facets = array_values($this->search->getDatacenterFacets(PortalScope::DOI));
        $idByCurrentName = Datacenter::query()
            ->whereIn('name', array_column($facets, 'name'))
            ->pluck('id', 'name')
            ->all();
        $keys = array_map(
            static fn (array $entry): string => mb_strtolower(trim($entry['datacenterName']), 'UTF-8'),
            $entries,
        );
        $idByAlias = DB::table('datacenter_name_aliases')
            ->whereIn('name_key', $keys)
            ->pluck('datacenter_id', 'name_key')
            ->all();
        $originalNameById = [];
        foreach (DB::table('datacenter_name_aliases')
            ->whereIn('datacenter_id', array_values($idByCurrentName))
            ->orderBy('id')
            ->get(['datacenter_id', 'name']) as $alias) {
            $originalNameById[(int) $alias->datacenter_id] ??= (string) $alias->name;
        }
        $byId = [];
        foreach ($entries as $entry) {
            $key = mb_strtolower(trim($entry['datacenterName']), 'UTF-8');
            $id = $idByAlias[$key] ?? $idByCurrentName[$entry['datacenterName']] ?? null;
            if ($id !== null) {
                $byId[(int) $id] = $entry;
            }
        }

        return array_map(function (array $facet) use ($byId, $idByCurrentName, $originalNameById): array {
            $id = $idByCurrentName[$facet['name']] ?? null;
            $entry = $id === null
                ? $this->fallback($facet['name'])
                : ($byId[(int) $id] ?? $this->fallback($facet['name'], $originalNameById[(int) $id] ?? $facet['name']));

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
        }, $facets);
    }

    /** @return DataCentre */
    private function fallback(string $name, ?string $originalName = null): array
    {
        return [
            // Reserve this namespace in the editorial catalogue. Unlike slugifying
            // names, hashing also distinguishes punctuation and non-Latin names.
            'slug' => 'datacenter-'.hash('sha256', $originalName ?? $name),
            'datacenterName' => $name,
            'displayName' => $name,
            'shortName' => $name,
            'logo' => null,
            'description' => ['Explore the publications assigned to this data centre in the GFZ Data Services Data Portal.'],
            'links' => [],
        ];
    }
}
