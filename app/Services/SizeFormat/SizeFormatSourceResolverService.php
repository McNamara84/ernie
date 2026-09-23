<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;

final class SizeFormatSourceResolverService
{
    /**
     * @return list<array{kind: 'ftp_url'|'additional_download_link', url: string, landing_page_id: int, link_id: int|null, label: string|null}>
     */
    public function resolve(Resource $resource): array
    {
        $resource->loadMissing('landingPage.links');
        $landingPage = $resource->landingPage;

        if (! $landingPage instanceof LandingPage || $landingPage->isExternal() || $landingPage->downloads_unavailable) {
            return [];
        }

        $sources = [];
        $seen = [];
        $ftpUrl = trim((string) $landingPage->ftp_url);

        if ($ftpUrl !== '') {
            $this->append($sources, $seen, [
                'kind' => 'ftp_url',
                'url' => $ftpUrl,
                'landing_page_id' => $landingPage->id,
                'link_id' => null,
                'label' => $landingPage->primary_download_label,
            ]);
        }

        foreach ($landingPage->links as $link) {
            if ($link->kind !== LandingPageLink::KIND_DOWNLOAD) {
                continue;
            }

            $url = trim((string) $link->url);

            if ($url === '') {
                continue;
            }

            $this->append($sources, $seen, [
                'kind' => 'additional_download_link',
                'url' => $url,
                'landing_page_id' => $landingPage->id,
                'link_id' => $link->id,
                'label' => $link->label,
            ]);
        }

        return $sources;
    }

    /**
     * @param  list<array{kind: 'ftp_url'|'additional_download_link', url: string, landing_page_id: int, link_id: int|null, label: string|null}>  $sources
     * @param  array<string, true>  $seen
     * @param  array{kind: 'ftp_url'|'additional_download_link', url: string, landing_page_id: int, link_id: int|null, label: string|null}  $source
     */
    private function append(array &$sources, array &$seen, array $source): void
    {
        $key = $this->canonicalUrl($source['url']);

        if ($key === '' || isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $sources[] = $source;
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return trim($url);
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }
}
