<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\Iso19115\Iso19115ResourceProfileService;

/**
 * Builds canonical, anonymous metadata representation links for landing pages.
 */
class LandingPageMetadataLinkService
{
    public function __construct(
        private readonly Iso19115ResourceProfileService $isoProfile,
    ) {}

    /**
     * @return list<array{
     *     format: string,
     *     standard: string,
     *     label: string,
     *     url: string,
     *     mediaType: string,
     *     profile: string|null
     * }>
     */
    public function for(Resource $resource, LandingPage $landingPage): array
    {
        if (! $landingPage->isPublished() || $landingPage->doi_prefix === null) {
            return [];
        }

        $baseUrl = url($landingPage->getPublicPath().'/metadata');
        $links = [
            $this->link('datacite-xml', 'DataCite', 'DataCite XML', "{$baseUrl}/datacite.xml", 'application/vnd.datacite.datacite+xml'),
            $this->link('datacite-json', 'DataCite', 'DataCite JSON', "{$baseUrl}/datacite.json", 'application/vnd.datacite.datacite+json'),
            $this->link('datacite-jsonld', 'DataCite', 'DataCite JSON-LD', "{$baseUrl}/datacite.jsonld", 'application/ld+json'),
        ];

        if ($this->isoProfile->supports($resource)) {
            $links[] = $this->link(
                'iso19115-3',
                'ISO 19115-3',
                'ISO 19115-3:2023 XML',
                "{$baseUrl}/iso-19115-3.xml",
                'application/xml',
                (string) config('iso19115.profile'),
            );
        }

        return $links;
    }

    /**
     * @param  list<array{format: string, standard: string, label: string, url: string, mediaType: string, profile: string|null}>  $metadataLinks
     * @param  array{mimeType: string|null, contentLinks: list<array{url: string, mimeType: string}>, repositories: list<string>}  $content
     * @return list<array{rel: string, href: string, type: string|null, profile: string|null}>
     */
    public function signpostingFor(
        Resource $resource,
        LandingPage $landingPage,
        array $metadataLinks,
        array $content,
        ?string $license,
    ): array {
        if (! $landingPage->isPublished() || $landingPage->doi_prefix === null) {
            return [];
        }

        $primaryType = $resource->resourceType?->slug === 'software'
            ? 'https://schema.org/SoftwareSourceCode'
            : 'https://schema.org/Dataset';

        $links = [
            $this->signpostingLink('cite-as', 'https://doi.org/'.$landingPage->doi_prefix),
            $this->signpostingLink('type', $primaryType),
            $this->signpostingLink('type', 'https://schema.org/AboutPage'),
        ];

        foreach ($metadataLinks as $metadataLink) {
            $links[] = $this->signpostingLink(
                'describedby',
                $metadataLink['url'],
                $metadataLink['mediaType'],
                $metadataLink['profile'],
            );
        }

        if ($license !== null) {
            $links[] = $this->signpostingLink('license', $license);
        }

        foreach ($content['contentLinks'] as $contentLink) {
            $links[] = $this->signpostingLink('item', $contentLink['url'], $contentLink['mimeType']);
        }

        return $this->safeUniqueSignpostingLinks($links);
    }

    /**
     * Serialize the complete FAIR Signposting contract for an HTTP Link header.
     *
     * @param  array<mixed, mixed>  $links
     */
    public function toSignpostingHttpLinkHeader(array $links): ?string
    {
        $values = [];
        $quote = chr(34);

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $rel = $link['rel'] ?? null;
            $href = $link['href'] ?? null;
            if (
                ! is_string($rel)
                || preg_match('/\A[a-z][a-z0-9-]*\z/', $rel) !== 1
                || ! is_string($href)
                || ! $this->isSafeAbsoluteHttpUrl($href)
            ) {
                continue;
            }

            $parameters = [
                '<'.$href.'>',
                'rel='.$quote.$this->quotedParameter($rel).$quote,
            ];

            $type = $link['type'] ?? null;
            if (is_string($type) && $type !== '') {
                $parameters[] = 'type='.$quote.$this->quotedParameter($type).$quote;
            }

            $profile = $link['profile'] ?? null;
            if (is_string($profile) && $profile !== '') {
                $parameters[] = 'profile='.$quote.$this->quotedParameter($profile).$quote;
            }

            $values[] = implode('; ', $parameters);
        }

        return $values !== [] ? implode(', ', array_values(array_unique($values))) : null;
    }

    /**
     * Serialize canonical metadata links for HTTP Signposting discovery.
     *
     * @param  array<mixed, mixed>  $links
     */
    public function toHttpLinkHeader(array $links): ?string
    {
        $values = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $url = $link['url'] ?? null;
            $mediaType = $link['mediaType'] ?? null;
            if (
                ! is_string($url)
                || ! is_string($mediaType)
                || ((! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')))
                || preg_match('/[\r\n<>]/', $url) === 1
            ) {
                continue;
            }

            $parameters = [
                "<{$url}>",
                'rel="describedby"',
                'type="'.$this->quotedParameter($mediaType).'"',
            ];
            $profile = $link['profile'] ?? null;
            if (is_string($profile) && $profile !== '') {
                $parameters[] = 'profile="'.$this->quotedParameter($profile).'"';
            }
            $values[] = implode('; ', $parameters);
        }

        return $values !== [] ? implode(', ', $values) : null;
    }

    /**
     * @return array{rel: string, href: string, type: string|null, profile: string|null}
     */
    private function signpostingLink(
        string $rel,
        string $href,
        ?string $type = null,
        ?string $profile = null,
    ): array {
        return compact('rel', 'href', 'type', 'profile');
    }

    /**
     * @param  list<array{rel: string, href: string, type: string|null, profile: string|null}>  $links
     * @return list<array{rel: string, href: string, type: string|null, profile: string|null}>
     */
    private function safeUniqueSignpostingLinks(array $links): array
    {
        $unique = [];

        foreach ($links as $link) {
            if (! $this->isSafeAbsoluteHttpUrl($link['href'])) {
                continue;
            }

            $key = implode('|', [
                $link['rel'],
                $link['href'],
                $link['type'] ?? '',
                $link['profile'] ?? '',
            ]);
            $unique[$key] = $link;
        }

        return array_values($unique);
    }

    private function isSafeAbsoluteHttpUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F<>]/', $url) === 1) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && is_string(parse_url($url, PHP_URL_HOST));
    }

    private function quotedParameter(string $value): string
    {
        return str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '', ''],
            $value,
        );
    }

    /**
     * @return array{
     *     format: string,
     *     standard: string,
     *     label: string,
     *     url: string,
     *     mediaType: string,
     *     profile: string|null
     * }
     */
    private function link(
        string $format,
        string $standard,
        string $label,
        string $url,
        string $mediaType,
        ?string $profile = null,
    ): array {
        return compact('format', 'standard', 'label', 'url', 'mediaType', 'profile');
    }
}
