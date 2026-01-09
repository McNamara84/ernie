<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL uses only safe HTTP/HTTPS schemes.
 *
 * This rule prevents XSS attacks by rejecting URLs with dangerous schemes
 * like `javascript:`, `data:`, `vbscript:`, etc. that could be exploited
 * when rendered as href attributes in anchor tags.
 *
 * Laravel's built-in `url` rule uses PHP's FILTER_VALIDATE_URL which accepts
 * arbitrary schemes. This rule enforces a strict allowlist of http/https only.
 *
 * Usage in validation:
 *   'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
 *
 * Note: Despite the name "ftp_url", we only allow http/https for security.
 * FTP URLs would require additional security considerations (credentials, etc.)
 */
final class SafeUrl implements ValidationRule
{
    /**
     * Allowed URL schemes for safe rendering in HTML href attributes.
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
        // Null/empty values should be handled by 'nullable' or 'required' rules
        if ($value === null || $value === '') {
            return;
        }

        // Must be a string
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        // Parse the URL to extract the scheme
        $parsedUrl = parse_url($value);

        // If parse_url fails, the URL is malformed
        if ($parsedUrl === false) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        // Extract and validate the scheme
        $scheme = $parsedUrl['scheme'] ?? null;

        if ($scheme === null) {
            $fail('The :attribute must include a URL scheme (http or https).');

            return;
        }

        // Case-insensitive check against allowed schemes
        if (! in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            $fail('The :attribute must use http or https protocol.');

            return;
        }

        // Additionally validate URL format using filter_var for robustness
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail('The :attribute must be a valid URL.');
        }
    }
}
