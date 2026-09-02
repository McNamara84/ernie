<?php

declare(strict_types=1);

namespace App\Services;

class LegacyLandingPageDecisionService
{
    /**
     * Decide whether a newly imported legacy resource receives an internal
     * landing page and whether that page may be published/synchronised.
     *
     * File visibility is intentionally not part of this decision. The legacy
     * resource publication status and the DataCite DOI state are authoritative.
     *
     * @return array{should_create: bool, should_publish: bool, should_sync: bool}
     */
    public function internalLandingPageDecision(?string $legacyPublicStatus, ?string $dataCiteState): array
    {
        $legacyPublicStatus = strtolower(trim((string) $legacyPublicStatus));
        $dataCiteState = strtolower(trim((string) $dataCiteState));
        $isReleased = in_array($legacyPublicStatus, ['released', 'published'], true);
        $shouldCreate = $isReleased || $legacyPublicStatus === 'pending';
        $shouldPublish = $isReleased && $dataCiteState === 'findable';

        return [
            'should_create' => $shouldCreate,
            'should_publish' => $shouldPublish,
            'should_sync' => $shouldPublish,
        ];
    }

    /**
     * Legacy test/delete DOIs should never create ERNIE resources.
     */
    public function shouldSkipLegacyDoi(string $doi): bool
    {
        $doi = strtolower(trim($doi));

        return preg_match('/(?:^|[^a-z0-9])(?:test|delete)(?:$|[^a-z0-9])/', $doi) === 1;
    }

    /**
     * DataCite URLs are only trusted for legacy resources that are known to use
     * external landing pages. Old GFZ Data Services runtime URLs must be ignored.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $datacenterNames
     */
    public function shouldImportDataCiteUrlAsExternal(
        string $doi,
        array $attributes,
        array $datacenterNames = [],
    ): bool {
        $url = isset($attributes['url']) ? trim((string) $attributes['url']) : '';

        if ($url === '' || $this->isLegacyDataServicesUrl($url)) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return $this->isGeofonExternalLandingPage($doi, $host, $datacenterNames);
    }

    public function isLegacyDataServicesUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && in_array($host, ['dataservices.gfz.de', 'dataservices.gfz-potsdam.de'], true);
    }

    /** @param list<string> $datacenterNames */
    private function isGeofonExternalLandingPage(string $doi, string $host, array $datacenterNames): bool
    {
        if (! in_array($host, ['geofon.gfz.de', 'geofon.gfz-potsdam.de'], true)) {
            return false;
        }

        $normalizedDoi = strtolower(trim($doi));

        if (str_starts_with($normalizedDoi, '10.14470/')
            || preg_match('/^(?:10\.1594\/gfz\.geofon|10\.5880\/geofon)\..+$/', $normalizedDoi) === 1) {
            return true;
        }

        foreach ($datacenterNames as $datacenterName) {
            if (strcasecmp(
                trim($datacenterName),
                LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
            ) === 0) {
                return true;
            }
        }

        return false;
    }
}
