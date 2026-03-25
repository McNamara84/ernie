<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Service for looking up download URLs from the legacy metaworks database.
 *
 * The metaworks database (sumario-pmd) stores file download URLs in the `file` table,
 * linked to resources via `file.resource_id`. This service queries that data by DOI
 * to enrich imported resources with download URLs during DataCite import.
 */
class MetaworksDownloadUrlService
{
    private const CONNECTION = 'metaworks';

    /**
     * Look up public file download URLs for a given DOI from the old metaworks database.
     *
     * DOI format in metaworks uses "/" (e.g. "10.5880/GFZ.5.4.2013.001"),
     * same format as DataCite.
     *
     * @return array<int, string> List of unique download URLs (may be empty)
     */
    public function lookupFileUrls(string $doi): array
    {
        // Find old resource_id by DOI
        $oldResource = DB::connection(self::CONNECTION)
            ->table('resource')
            ->where('identifier', $doi)
            ->select('id')
            ->first();

        if ($oldResource === null) {
            return [];
        }

        // Get all public file URLs for that resource, deduplicated
        return DB::connection(self::CONNECTION)
            ->table('file')
            ->where('resource_id', $oldResource->id)
            ->where('visible', 'public')
            ->orderBy('id')
            ->pluck('url')
            ->unique()
            ->values()
            ->all();
    }
}
