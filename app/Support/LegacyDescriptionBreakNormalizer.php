<?php

declare(strict_types=1);

namespace App\Support;

final class LegacyDescriptionBreakNormalizer
{
    private const BREAK_TAG = '<br\s*/?>';

    /**
     * Normalize the representation used by a stored Description value.
     *
     * @return array{value: string, replacements: int}
     */
    public function normalizeStoredValue(string $value): array
    {
        if (preg_match('~'.self::BREAK_TAG.'~i', $value) === 1) {
            return $this->normalizeHtml($value);
        }

        return $this->normalizePlainText($value);
    }

    /** @return array{value: string, replacements: int} */
    public function normalizeHtml(string $value): array
    {
        $replacements = 0;
        $normalized = preg_replace_callback(
            '~'.self::BREAK_TAG.'(?:\s*'.self::BREAK_TAG.')+~i',
            static function (array $match) use (&$replacements): string {
                preg_match_all('~'.self::BREAK_TAG.'~i', $match[0], $tokens);
                $count = count($tokens[0]);
                $kept = (int) ceil($count / 2);
                $replacements += $count - $kept;

                return str_repeat('<br>', $kept);
            },
            $value,
        );

        return [
            'value' => $normalized ?? $value,
            'replacements' => $replacements,
        ];
    }

    /** @return array{value: string, replacements: int} */
    public function normalizePlainText(string $value): array
    {
        $replacements = 0;
        $normalized = preg_replace_callback(
            '/(?:\r\n|\r|\n)(?:[ \t]*(?:\r\n|\r|\n))+/',
            static function (array $match) use (&$replacements): string {
                preg_match_all('/\r\n|\r|\n/', $match[0], $tokens);
                $count = count($tokens[0]);
                $kept = (int) ceil($count / 2);
                $replacements += $count - $kept;

                return str_repeat($tokens[0][0], $kept);
            },
            $value,
        );

        return [
            'value' => $normalized ?? $value,
            'replacements' => $replacements,
        ];
    }
}
