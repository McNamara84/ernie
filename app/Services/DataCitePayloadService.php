<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteVerificationException;
use App\Exceptions\JsonValidationException;
use App\Models\Resource;
use App\Support\DataCiteSchemaVersion;
use App\Support\IgsnIdentifier;

final class DataCitePayloadService
{
    public function __construct(
        private readonly ResourceDoiPublicationTargetService $targets,
        private readonly DataCiteJsonExporter $exporter,
    ) {}

    /**
     * Prepare the request using local metadata, before publication or transport.
     *
     * @param  list<string>  $allowedPrefixes
     * @return array{data: array{type: string, attributes: array<string, mixed>}}
     */
    public function publication(Resource $resource, string $prefix, array $allowedPrefixes, bool $igsn): array
    {
        app(EmbargoService::class)->assertCanRegister($resource);
        if ($igsn && ! $resource->titles->contains(fn ($title): bool => $title->isMainTitle() && trim($title->value) !== '')) {
            throw new DataCiteVerificationException('metadata_incomplete', 'A main title is required for IGSN registration.');
        }
        if (! $igsn && ! $resource->isComplete()) {
            throw new DataCiteVerificationException('metadata_incomplete', 'Complete the required resource metadata before publication.');
        }
        $doi = trim((string) $resource->doi);
        $igsnPrefix = $igsn ? ($allowedPrefixes[0] ?? '') : null;
        if ($igsnPrefix !== null) {
            $doi = IgsnIdentifier::forRegistration($doi, $igsnPrefix);
            $prefix = explode('/', $doi, 2)[0];
        }
        if ($doi !== '' && Resource::forDataCiteDoi($doi, $igsnPrefix)->whereKeyNot($resource->id)->exists()) {
            throw new DataCiteVerificationException('ambiguous_local_doi', 'This DOI is assigned to more than one local resource. Resolve the duplicate before publication.');
        }
        if (! in_array(strtolower($prefix), $allowedPrefixes, true)
            || ($doi !== '' && ! preg_match('/^10\.\d{4,}(?:\.\d+)*\/\S+$/', $doi))
            || ($igsn && $doi === '')) {
            throw new DataCiteVerificationException('invalid_doi_prefix', 'The DOI or prefix is not permitted in this DataCite environment.');
        }

        $target = $this->targets->target($resource);
        $attributes = $this->exporter->export($resource)['data']['attributes'];
        if ($igsn) {
            $attributes['doi'] = $doi;
            $attributes['publicationYear'] = (string) now(config('app.timezone'))->year;
            $attributes['types']['resourceTypeGeneral'] = 'PhysicalObject';
        }
        $this->validateAttributes($attributes, $doi !== '');
        if ($doi === '') {
            unset($attributes['doi']);
            $attributes['prefix'] = $prefix;
        }

        return ['data' => ['type' => 'dois', 'attributes' => [...$attributes, 'url' => $target, 'event' => 'publish']]];
    }

    /** @param array<string, mixed> $attributes */
    private function validateAttributes(array $attributes, bool $strict): void
    {
        try {
            app(JsonSchemaValidator::class)->validate($attributes, strictMode: $strict);
        } catch (JsonValidationException $exception) {
            throw new DataCiteVerificationException('metadata_invalid', implode(' ', $exception->getErrorMessages()));
        }
    }

    /** @return array{data: array{type: string, id: string, attributes: array<string, mixed>}} */
    public function metadataUpdate(Resource $resource, ?string $igsnPrefix = null): array
    {
        if (! $resource->doi) {
            throw new DataCiteVerificationException('missing_doi', "Resource #{$resource->id} must have a DOI to update metadata.");
        }
        $doi = $igsnPrefix !== null ? IgsnIdentifier::forRegistration($resource->doi, $igsnPrefix) : $resource->doi;
        if ($igsnPrefix !== null && (! str_starts_with(strtolower($doi), $igsnPrefix.'/')
            || ! preg_match('/^10\.\d{4,}(?:\.\d+)*\/\S+$/', $doi))) {
            throw new DataCiteVerificationException('invalid_doi_prefix', 'The DOI or prefix is not permitted in the IGSN repository.');
        }
        $page = $resource->landingPage;
        if ($page === null) {
            throw new DataCiteVerificationException('landing_page_required', "Resource #{$resource->id} must have a landing page to update metadata.");
        }
        app(EmbargoService::class)->assertCanUpdateMetadata($resource);
        if (! $page->is_published) {
            throw new DataCiteVerificationException('landing_page_not_published', 'Publish the landing page explicitly before updating DataCite metadata. Local changes remain saved.');
        }
        $attributes = $this->exporter->export($resource)['data']['attributes'];
        if ($resource->igsnMetadata !== null) {
            $attributes['doi'] = $doi;
            $attributes['types']['resourceTypeGeneral'] = 'PhysicalObject';
            $this->validateAttributes($attributes, true);
        }

        return ['data' => [
            'type' => 'dois', 'id' => $doi,
            'attributes' => [...$attributes, 'url' => $page->public_url, 'schemaVersion' => DataCiteSchemaVersion::KERNEL_4],
        ]];
    }
}
