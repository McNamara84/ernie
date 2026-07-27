<?php

declare(strict_types=1);

namespace App\Services;

final class LandingPageLicenseResolverService
{
    /** @param array<int, mixed> $rightsList */
    public function resolve(array $rightsList): ?string
    {
        foreach ($rightsList as $rights) {
            if (! is_array($rights)) {
                continue;
            }

            $scheme = $rights['rightsIdentifierScheme'] ?? null;
            $schemeUri = $rights['schemeUri'] ?? null;
            $identifier = $rights['rightsIdentifier'] ?? null;

            if (
                is_string($scheme)
                && strcasecmp($scheme, 'SPDX') === 0
                && is_string($schemeUri)
                && is_string($identifier)
                && trim($identifier) !== ''
            ) {
                $spdxUri = rtrim(trim($schemeUri), '/').'/'.rawurlencode(trim($identifier));
                if ($this->isSafeAbsoluteHttpUrl($spdxUri)) {
                    return $spdxUri;
                }
            }
        }

        foreach ($rightsList as $rights) {
            if (! is_array($rights) || ! is_string($rights['rightsUri'] ?? null)) {
                continue;
            }

            $rightsUri = trim($rights['rightsUri']);
            if ($this->isSafeAbsoluteHttpUrl($rightsUri)) {
                return $rightsUri;
            }
        }

        return null;
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
}
