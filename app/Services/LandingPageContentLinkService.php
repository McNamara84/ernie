<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\SizeFormat\SizeFormatFormatNormalizerService;

final class LandingPageContentLinkService
{
    /**
     * @return array{
     *     mimeType: string|null,
     *     contentLinks: list<array{url: string, mimeType: string}>,
     *     repositories: list<string>
     * }
     */
    public function resolve(Resource $resource, LandingPage $landingPage): array
    {
        $resource->loadMissing('formats');
        $landingPage->loadMissing(['files', 'links']);

        $mimeType = $this->firstValidMimeType($resource);
        $repositories = $this->repositoryUrls($landingPage);

        if ($landingPage->downloads_unavailable || $mimeType === null) {
            return [
                'mimeType' => $mimeType,
                'contentLinks' => [],
                'repositories' => $repositories,
            ];
        }

        $urls = [];
        $files = $landingPage->files
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();

        if ($files->isNotEmpty()) {
            foreach ($files as $file) {
                $this->appendSafeUniqueUrl($urls, $file->url);
            }
        } else {
            $this->appendSafeUniqueUrl($urls, $landingPage->ftp_url);
        }

        foreach ($landingPage->links
            ->where('kind', LandingPageLink::KIND_DOWNLOAD)
            ->sortBy([['position', 'asc'], ['id', 'asc']]) as $link) {
            $this->appendSafeUniqueUrl($urls, $link->url);
        }

        return [
            'mimeType' => $mimeType,
            'contentLinks' => array_map(
                static fn (string $url): array => ['url' => $url, 'mimeType' => $mimeType],
                array_values($urls),
            ),
            'repositories' => $repositories,
        ];
    }

    private function firstValidMimeType(Resource $resource): ?string
    {
        foreach ($resource->formats->sortBy('id') as $format) {
            $normalized = SizeFormatFormatNormalizerService::normalize($format->value);

            if ($this->isValidMimeType($normalized)) {
                return $normalized;
            }
        }

        return null;
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
