<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\LandingPageDownloadAvailabilityService;

/** @phpstan-type DownloadSource array{kind: 'ftp_url'|'imported_file'|'additional_download_link', url: string, landing_page_id: int, link_id: int|null, file_id?: int, label: string|null} */
final class SizeFormatSourceResolverService
{
    public function __construct(private readonly LandingPageDownloadAvailabilityService $availability) {}

    /**
     * @return list<DownloadSource>
     */
    public function resolve(Resource $resource): array
    {
        $resource->loadMissing(['landingPage.links', 'landingPage.files']);
        $landingPage = $resource->landingPage;

        if (! $landingPage instanceof LandingPage || $landingPage->isExternal() || ! $this->availability->isAvailable($landingPage)) {
            return [];
        }

        $sources = [];
        $seen = [];
        $ftpUrl = trim((string) $landingPage->ftp_url);
        $files = $this->availability->usableFiles($landingPage);

        if ($files->isNotEmpty()) {
            foreach ($files as $file) {
                $this->append($sources, $seen, [
                    'kind' => 'imported_file',
                    'url' => trim($file->url),
                    'landing_page_id' => $landingPage->id,
                    'link_id' => null,
                    'file_id' => $file->id,
                    'label' => $file->label,
                ]);
            }
        } elseif ($this->availability->isUsableUrl($ftpUrl)) {
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

            if (! $this->availability->isUsableUrl($url)) {
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
     * @param  list<DownloadSource>  $sources
     * @param  array<string, true>  $seen
     * @param  DownloadSource  $source
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
