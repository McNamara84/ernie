<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\UriHelper;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL is a safe base domain for composing redirect URLs.
 *
 * This rule is stricter than SafeUrl: it enforces that the value is a pure
 * domain origin (scheme + host) with no path beyond "/", no query string,
 * no fragment, and no userinfo (credentials).
 *
 * Valid examples:
 *   - https://geofon.gfz.de/
 *   - https://data.gfz.de
 *   - http://example.org/
 *
 * Rejected examples:
 *   - https://geofon.gfz.de/some/path  (has path)
 *   - https://example.org/?q=1          (has query)
 *   - https://example.org/#section      (has fragment)
 *   - https://user:pass@example.org/    (has credentials)
 *   - ftp://files.example.org/          (non-http scheme)
 *
 * The value is normalized (trailing slash ensured) before validation and
 * stored in normalized form so that max-length checks are accurate.
 */
final class SafeDomainUrl implements ValidationRule
{
    /**
     * Allowed URL schemes.
     *
     * @var array<int, string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $uri = UriHelper::parse($value);

        if ($uri === null) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        // Require a scheme
        if ($scheme === null) {
            $fail('The :attribute must include a URL scheme (http or https).');

            return;
        }

        // Enforce http/https only
        if (! in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            $fail('The :attribute must use http or https protocol.');

            return;
        }

        // Require a host
        if ($host === null || $host === '') {
            $fail('The :attribute must be a valid URL with a host.');

            return;
        }

        // Reject userinfo (credentials like user:pass@host)
        if ($uri->getUserInfo() !== null) {
            $fail('The :attribute must not contain credentials.');

            return;
        }

        // Reject non-trivial paths (only "/" or empty is allowed)
        $path = $uri->getPath();
        if ($path !== '' && $path !== '/') {
            $fail('The :attribute must be a domain without a path (e.g. https://example.org/).');

            return;
        }

        // Reject query strings
        if ($uri->getQuery() !== null) {
            $fail('The :attribute must not contain a query string.');

            return;
        }

        // Reject fragments
        if ($uri->getFragment() !== null) {
            $fail('The :attribute must not contain a fragment.');

            return;
        }
    }
}
