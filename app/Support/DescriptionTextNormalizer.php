<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes DataCite description text without interpreting arbitrary HTML.
 */
final class DescriptionTextNormalizer
{
    private const GREATER_THAN_ENTITY = '/&(?:gt|#0*62|#x0*3e);/i';

    private const LESS_THAN_ENTITY = '/&(?:lt|#0*60|#x0*3c);/i';

    /**
     * @return array{value: string, replacements: int}
     */
    public function normalize(string $value): array
    {
        $replacements = 0;
        $normalized = preg_replace(self::GREATER_THAN_ENTITY, '>', $value, -1, $greaterThanCount);
        $normalized = $normalized ?? $value;
        $replacements += $greaterThanCount;

        $lessThanNormalized = preg_replace(self::LESS_THAN_ENTITY, '<', $normalized, -1, $lessThanCount);
        $replacements += $lessThanCount;

        return [
            'value' => $lessThanNormalized ?? $normalized,
            'replacements' => $replacements,
        ];
    }
}
