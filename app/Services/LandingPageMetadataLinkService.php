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
            $this->link('datacite-xml', 'DataCite', 'DataCite XML', "{$baseUrl}/datacite.xml", 'application/xml'),
            $this->link('datacite-json', 'DataCite', 'DataCite JSON', "{$baseUrl}/datacite.json", 'application/json'),
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
