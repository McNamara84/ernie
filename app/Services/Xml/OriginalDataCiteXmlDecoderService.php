<?php

declare(strict_types=1);

namespace App\Services\Xml;

/**
 * Decodes the original DataCite XML embedded in API records.
 */
final class OriginalDataCiteXmlDecoderService
{
    public function decode(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_contains($value, '<')) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) && str_contains($decoded, '<') ? $decoded : null;
    }
}
