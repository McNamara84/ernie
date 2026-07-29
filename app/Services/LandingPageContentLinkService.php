<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Format;
use App\Models\Resource;
use App\Models\Size;
use App\Services\SizeFormat\DigitalContentSizeService;
use App\Services\SizeFormat\SizeFormatFormatNormalizerService;

final class LandingPageContentLinkService
{
    /**
     * @return array{
     *     mimeType: string|null,
     *     contentLinks: list<array{url: string, mimeType: string, contentSize: string|null}>,
     *     repositories: list<string>
     * }
     */
    public function resolve(Resource $resource, LandingPage $landingPage): array
    {
        $resource->loadMissing(['resourceType', 'formats', 'sizes']);
        $landingPage->loadMissing([
            'ftpFormat',
            'ftpSize',
            'files.format',
            'files.size',
            'links.format',
            'links.size',
        ]);

        $repositories = $this->repositoryUrls($landingPage);

        if ($landingPage->downloads_unavailable) {
            return [
                'mimeType' => null,
                'contentLinks' => [],
                'repositories' => $repositories,
            ];
        }

        $contentLinks = [];
        $files = $landingPage->files
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();

        if ($files->isNotEmpty()) {
            foreach ($files as $file) {
                $this->appendContentLink($contentLinks, $resource, $file->url, $file->format, $file->size);
            }
        } else {
            $this->appendContentLink(
                $contentLinks,
                $resource,
                $landingPage->ftp_url,
                $landingPage->ftpFormat,
                $landingPage->ftpSize,
            );
        }

        foreach ($landingPage->links
            ->where('kind', LandingPageLink::KIND_DOWNLOAD)
            ->sortBy([['position', 'asc'], ['id', 'asc']]) as $link) {
            $this->appendContentLink($contentLinks, $resource, $link->url, $link->format, $link->size);
        }

        $firstContentLink = reset($contentLinks);

        return [
            'mimeType' => is_array($firstContentLink) ? $firstContentLink['mimeType'] : null,
            'contentLinks' => array_values($contentLinks),
            'repositories' => $repositories,
        ];
    }

    /**
     * @param  array<string, array{url: string, mimeType: string, contentSize: string|null}>  $contentLinks
     */
    private function appendContentLink(
        array &$contentLinks,
        Resource $resource,
        mixed $candidateUrl,
        ?Format $format,
        ?Size $size,
    ): void {
        if (! is_string($candidateUrl)) {
            return;
        }

        $url = trim($candidateUrl);
        if (! $this->isSafeAbsoluteHttpUrl($url)
            || $format === null
            || $format->resource_id !== $resource->id) {
            return;
        }

        $mimeType = SizeFormatFormatNormalizerService::normalize($format->value);
        if (! $this->isValidMimeType($mimeType)) {
            return;
        }

        $contentSize = null;
        if ($size !== null && $size->resource_id === $resource->id) {
            $contentSize = app(DigitalContentSizeService::class)->forResource($size, $resource);
        }

        $contentLinks[$url] = [
            'url' => $url,
            'mimeType' => $mimeType,
            'contentSize' => $contentSize,
        ];
    }

    /** @return list<string> */
    private function repositoryUrls(LandingPage $landingPage): array
    {
        $urls = [];

        foreach ($landingPage->links
            ->where('kind', LandingPageLink::KIND_REPOSITORY)
            ->sortBy([['position', 'asc'], ['id', 'asc']]) as $link) {
            $this->appendSafeUniqueUrl($urls, $link->url);
        }

        return array_values($urls);
    }

    private function isValidMimeType(string $value): bool
    {
        return preg_match(
            '/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/i',
            $value,
        ) === 1;
    }

    /** @param array<string, string> $urls */
    private function appendSafeUniqueUrl(array &$urls, mixed $candidate): void
    {
        if (! is_string($candidate)) {
            return;
        }

        $url = trim($candidate);
        if (! $this->isSafeAbsoluteHttpUrl($url)) {
            return;
        }

        $urls[$url] = $url;
    }

    private function isSafeAbsoluteHttpUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F<>]/', $url) === 1) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && is_string($host)
            && $host !== '';
    }
}
