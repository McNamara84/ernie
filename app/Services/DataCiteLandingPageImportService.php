<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataCiteLandingPageImportService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{changed: bool, created: bool, landing_page: LandingPage|null}
     */
    public function createExternalForResource(Resource $resource, array $attributes): array
    {
        if (! $resource->exists) {
            return $this->result();
        }

        $url = isset($attributes['url']) ? trim((string) $attributes['url']) : '';

        if ($url === '') {
            return $this->result();
        }

        $parts = $this->parseExternalUrl($url);

        if ($parts === null) {
            Log::warning('Skipping invalid DataCite landing page URL during import', [
                'resource_id' => $resource->id,
                'doi' => $resource->doi,
                'url' => mb_substr($url, 0, 512),
            ]);

            return $this->result();
        }

        $result = DB::transaction(function () use ($resource, $attributes, $parts): array {
            $existingLandingPage = LandingPage::query()
                ->where('resource_id', $resource->id)
                ->lockForUpdate()
                ->first();

            if ($existingLandingPage !== null) {
                return $this->result(landingPage: $existingLandingPage);
            }

            $domain = LandingPageDomain::query()->firstOrCreate([
                'domain' => $parts['domain'],
            ]);

            $isFindable = strtolower(trim((string) ($attributes['state'] ?? ''))) === 'findable';

            $landingPage = new LandingPage([
                'resource_id' => $resource->id,
                'template' => 'external',
                'external_domain_id' => $domain->id,
                'external_path' => $parts['path'],
                'ftp_url' => null,
                'downloads_unavailable' => false,
                'is_published' => $isFindable,
                'published_at' => $isFindable ? now() : null,
            ]);

            $landingPage->save();

            return $this->result(changed: true, created: true, landingPage: $landingPage->fresh(['externalDomain']) ?? $landingPage);
        });

        if ($result['changed']) {
            CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->forget();
        }

        return $result;
    }

    /**
     * @return array{domain: string, path: string}|null
     */
    public function parseExternalUrl(string $url): ?array
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $domain = "{$scheme}://{$host}{$port}/";
        $path = ltrim((string) ($parts['path'] ?? ''), '/');

        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $path .= '#'.$parts['fragment'];
        }

        if (mb_strlen($domain) > 768 || mb_strlen($path) > 2048) {
            return null;
        }

        return [
            'domain' => $domain,
            'path' => $path,
        ];
    }

    /**
     * @return array{changed: bool, created: bool, landing_page: LandingPage|null}
     */
    private function result(bool $changed = false, bool $created = false, ?LandingPage $landingPage = null): array
    {
        return [
            'changed' => $changed,
            'created' => $created,
            'landing_page' => $landingPage,
        ];
    }
}
