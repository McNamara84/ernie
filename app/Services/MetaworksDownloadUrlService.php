<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UriHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Look up file download URLs for a given DOI from the old metaworks database.
     *
     * Returns all file URLs (public and non-public) along with a flag indicating
     * whether all files have public visibility. The caller uses this to determine
     * whether the resulting landing page should be published immediately.
     *
     * DOI format in metaworks uses "/" (e.g. "10.5880/GFZ.5.4.2013.001"),
     * same format as DataCite.
     *
     * @return array{urls: array<int, string>, allPublic: bool} URLs and visibility flag
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
            return ['urls' => [], 'allPublic' => false];
        }

        // Get all file records for that resource (public and non-public)
        $files = DB::connection(self::CONNECTION)
            ->table('file')
            ->where('resource_id', $oldResource->id)
            ->orderBy('id')
            ->get(['url', 'visible']);

        if ($files->isEmpty()) {
            return ['urls' => [], 'allPublic' => false];
        }

        // Determine if all files are publicly visible
        $allPublic = $files->every(fn (object $file): bool => $file->visible === 'public');

        // Deduplicate URLs and filter to valid absolute HTTP(S) links (max 2048 chars)
        // to prevent XSS from legacy data containing javascript:, data: or other unsafe schemes,
        // and reject malformed URLs like "http:foo" that lack a host component.
        $urls = $files->pluck('url')
            ->unique()
            ->values()
            ->filter(function (string $url) use ($doi): bool {
                if (mb_strlen($url) > 2048) {
                    Log::warning('Skipping overly long metaworks URL', ['doi' => $doi, 'length' => mb_strlen($url)]);

                    return false;
                }

                $uri = UriHelper::parse($url);
                $scheme = $uri?->getScheme();
                $isHttp = $scheme !== null && in_array(strtolower($scheme), ['http', 'https'], true);

                if ($uri === null || ! $isHttp || $uri->getHost() === null || $uri->getHost() === '') {
                    Log::warning('Skipping invalid metaworks URL', ['doi' => $doi, 'url' => $url]);

                    return false;
                }

                return true;
            })->values()->all();

        return ['urls' => $urls, 'allPublic' => $allPublic];
    }
}
