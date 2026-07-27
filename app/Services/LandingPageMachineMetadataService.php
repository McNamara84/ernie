<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\Resource;

final class LandingPageMachineMetadataService
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly LandingPageContentLinkService $contentLinkService,
        private readonly LandingPageLicenseResolverService $licenseResolver,
        private readonly LandingPageMetadataLinkService $metadataLinkService,
        private readonly SchemaOrgJsonLdExporter $schemaOrgExporter,
        private readonly DataCiteJsonExporter $dataCiteExporter,
    ) {}

    /**
     * @return array{
     *     jsonLdJson: string,
     *     dublinCore: list<array{name: string, content: string}>,
     *     signpostingLinks: list<array{rel: string, href: string, type: string|null, profile: string|null}>,
     *     metadataLinks: list<array{format: string, standard: string, label: string, url: string, mediaType: string, profile: string|null}>
     * }|null
     */
    public function for(Resource $resource, LandingPage $landingPage): ?array
    {
        if (! $landingPage->isPublished() || $landingPage->doi_prefix === null) {
            return null;
        }

        $content = $this->contentLinkService->resolve($resource, $landingPage);
        $jsonLd = $this->schemaOrgExporter->export($resource, $landingPage, $content);
        $attributes = $this->dataCiteExporter->export($resource)['data']['attributes'];
        $rightsList = is_array($attributes['rightsList'] ?? null) ? $attributes['rightsList'] : [];
        $license = $this->licenseResolver->resolve($rightsList);
        $metadataLinks = $this->metadataLinkService->for($resource, $landingPage);
        $signpostingLinks = $this->metadataLinkService->signpostingFor(
            $resource,
            $landingPage,
            $metadataLinks,
            $content,
            $license,
        );

        return [
            'jsonLdJson' => json_encode($jsonLd, self::JSON_FLAGS),
            'dublinCore' => $this->dublinCore($attributes, $jsonLd, $license, $landingPage),
            'signpostingLinks' => $signpostingLinks,
            'metadataLinks' => $metadataLinks,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $jsonLd
     * @return list<array{name: string, content: string}>
     */
    private function dublinCore(array $attributes, array $jsonLd, ?string $license, LandingPage $landingPage): array
    {
        $tags = [];
        $doiUrl = 'https://doi.org/'.$landingPage->doi_prefix;
        $this->appendTag($tags, 'DC.identifier', $doiUrl);
        $this->appendTag($tags, 'DC.title', $this->mainTitle($attributes['titles'] ?? []));

        $creators = is_array($attributes['creators'] ?? null) ? $attributes['creators'] : [];
        foreach ($creators as $creator) {
            if (is_array($creator)) {
                $this->appendTag($tags, 'DC.creator', $creator['name'] ?? null);
            }
        }

        $publisher = is_array($attributes['publisher'] ?? null) ? $attributes['publisher'] : [];
        $this->appendTag($tags, 'DC.publisher', $publisher['name'] ?? null);
        $this->appendTag($tags, 'DC.date', $jsonLd['datePublished'] ?? $attributes['publicationYear'] ?? null);
        $this->appendTag($tags, 'DC.rights', $license);

        $types = is_array($attributes['types'] ?? null) ? $attributes['types'] : [];
        $this->appendTag($tags, 'DC.type', $types['resourceTypeGeneral'] ?? null);

        return $tags;
    }

    /** @param array<int, mixed> $titles */
    private function mainTitle(array $titles): ?string
    {
        foreach ($titles as $title) {
            if (is_array($title) && ! isset($title['titleType']) && is_string($title['title'] ?? null)) {
                return $title['title'];
            }
        }

        $first = $titles[0] ?? null;

        return is_array($first) && is_string($first['title'] ?? null) ? $first['title'] : null;
    }

    /**
     * @param  list<array{name: string, content: string}>  $tags
     */
    private function appendTag(array &$tags, string $name, mixed $content): void
    {
        if (! is_scalar($content)) {
            return;
        }

        $value = trim((string) $content);
        if ($value !== '') {
            $tags[] = ['name' => $name, 'content' => $value];
        }
    }
}
