<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\Resource;
use App\Services\DescriptionFormattingService;
use App\Support\IdentifierUrl;

final class DcatApReadinessService
{
    public function __construct(private readonly DcatApResourceProfileService $profile, private readonly DescriptionFormattingService $text) {}

    public function available(Resource $resource): bool
    {
        return $this->profile->isEnabled() && $this->errors($resource) === [];
    }

    /**
     * Content checks intentionally work with the feature flag disabled (pre-rollout audit).
     *
     * @return list<string>
     */
    public function errors(Resource $resource): array
    {
        $resource->loadMissing(['resourceType', 'igsnMetadata', 'landingPage', 'titles.titleType', 'descriptions.descriptionType']);
        $errors = [];
        if ($resource->igsnMetadata !== null || ! in_array($resource->resourceType?->slug, config('dcatap.resource_types', []), true)) {
            $errors[] = 'unsupported_resource_type';
        }
        if (! $resource->landingPage?->is_published || $resource->landingPage->doi_prefix === null) {
            $errors[] = 'not_published';
        }
        if (IdentifierUrl::doi($resource->doi) === null) {
            $errors[] = 'invalid_doi';
        }
        if (! $resource->titles->contains(fn ($title): bool => $title->isMainTitle() && $this->plain($title->value) !== '')) {
            $errors[] = 'missing_main_title';
        }
        if (! $resource->descriptions->contains(fn ($description): bool => $description->isAbstract() && $this->plain($description->value) !== '')) {
            $errors[] = 'missing_abstract';
        }
        if ($resource->updated_at === null && $resource->landingPage?->published_at === null) {
            $errors[] = 'missing_metadata_timestamp';
        }

        return $errors;
    }

    public function plain(?string $text): string
    {
        return trim($this->text->formatForStorage((string) $text)['plainText']);
    }

    public function validateConfiguration(): void
    {
        foreach (['title', 'description', 'publisher'] as $key) {
            if (trim((string) config('dcatap.catalog.'.$key)) === '') {
                throw new \LogicException('Missing DCAT-AP catalog configuration: '.$key);
            }
        }
        if (IdentifierUrl::http(config('dcatap.catalog.uri')) === null) {
            throw new \LogicException('Invalid DCAT-AP catalog URI');
        }
    }
}
