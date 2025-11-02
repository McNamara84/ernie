<?php

namespace App\Support;

class GcmdUriHelper
{
    /**
     * Pattern for extracting UUID from GCMD URI.
     * Supports both old and new GCMD URI formats:
     * - Old: http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/{uuid}
     * - New: https://gcmd.earthdata.nasa.gov/kms/concept/{uuid}
     *
     * UUID format: 8-4-4-4-12 hexadecimal digits (RFC 4122)
     */
    private const UUID_PATTERN = '/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/i';

    /**
     * New GCMD concept URI template.
     * Format: https://gcmd.earthdata.nasa.gov/kms/concept/{uuid}
     */
    private const NEW_URI_TEMPLATE = 'https://gcmd.earthdata.nasa.gov/kms/concept/%s';

    /**
     * Extract UUID from GCMD URI.
     *
     * @param  string|null  $uri  The GCMD URI (old or new format)
     * @return string|null The extracted UUID, or null if not found
     */
    public static function extractUuid(?string $uri): ?string
    {
        if (! $uri) {
            return null;
        }

        if (preg_match(self::UUID_PATTERN, $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Build new GCMD concept URI from UUID.
     *
     * @param  string  $uuid  The UUID
     * @return string The GCMD concept URI
     */
    public static function buildConceptUri(string $uuid): string
    {
        return sprintf(self::NEW_URI_TEMPLATE, $uuid);
    }

    /**
     * Convert old GCMD URI to new format by extracting UUID and rebuilding URI.
     *
     * @param  string|null  $oldUri  The old GCMD URI
     * @return string|null The new GCMD URI, or null if UUID cannot be extracted
     */
    public static function convertToNewUri(?string $oldUri): ?string
    {
        $uuid = self::extractUuid($oldUri);

        if (! $uuid) {
            return null;
        }

        return self::buildConceptUri($uuid);
    }
}
