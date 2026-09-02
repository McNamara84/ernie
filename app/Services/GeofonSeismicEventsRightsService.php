<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Right;
use App\Services\Spdx\SpdxLicenseLookup;
use RuntimeException;

/**
 * Supplies the authoritative CC0 license for GEOFON seismic-event imports.
 *
 * GEOFON event records do not carry their license in the imported metadata,
 * even though the complete collection is published under CC0. Resolving the
 * default through ERNIE's SPDX catalog keeps it separate from custom rights.
 */
final class GeofonSeismicEventsRightsService
{
    public const string LICENSE_IDENTIFIER = 'CC0-1.0';

    /**
     * @param  list<string>  $datacenterNames
     * @return array{
     *     rights: string,
     *     rightsUri: string,
     *     rightsIdentifier: string,
     *     rightsIdentifierScheme: string,
     *     schemeUri: string,
     *     source: string
     * }|null
     */
    public function rightsStatementForImport(string $doi, array $datacenterNames = []): ?array
    {
        if (! $this->appliesTo($doi, $datacenterNames)) {
            return null;
        }

        $right = Right::query()
            ->where('identifier', self::LICENSE_IDENTIFIER)
            ->where('scheme_uri', SpdxLicenseLookup::SCHEME_URI)
            ->first();

        if (! $right instanceof Right) {
            throw new RuntimeException(sprintf(
                'The SPDX catalog license %s is required for GEOFON Seismic Events imports.',
                self::LICENSE_IDENTIFIER,
            ));
        }

        return [
            'rights' => $right->name,
            'rightsUri' => $right->uri ?? SpdxLicenseLookup::licensePageUrl($right->identifier),
            'rightsIdentifier' => $right->identifier,
            'rightsIdentifierScheme' => SpdxLicenseLookup::RIGHTS_IDENTIFIER_SCHEME,
            'schemeUri' => SpdxLicenseLookup::SCHEME_URI,
            'source' => 'geofon-seismic-events-default',
        ];
    }

    /** @param list<string> $datacenterNames */
    private function appliesTo(string $doi, array $datacenterNames): bool
    {
        foreach ($datacenterNames as $datacenterName) {
            if (strcasecmp(trim($datacenterName), LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER) === 0) {
                return true;
            }
        }

        $normalizedDoi = preg_replace(
            '/^(?:doi:\s*|https?:\/\/(?:doi\.org|dx\.doi\.org)\/)/i',
            '',
            trim($doi),
        ) ?? trim($doi);

        return preg_match('/^(?:10\.1594\/gfz\.geofon|10\.5880\/geofon)\..+$/i', $normalizedDoi) === 1;
    }
}
