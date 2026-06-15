<?php

declare(strict_types=1);

namespace App\Support;

class IgsnIdentifier
{
    public static function normalizeInputToDoi(mixed $input, ?string $prefix = null): ?string
    {
        if ($input === null) {
            return null;
        }

        if (is_numeric($input)) {
            $input = (string) $input;
        }

        if (! is_string($input)) {
            return null;
        }

        $value = trim($input);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^https?:\/\/(?:dx\.)?doi\.org\/(.+)$/i', $value, $matches)) {
            $value = $matches[1];
        }

        $normalizedPrefix = self::prefix($prefix);
        $lowerValue = strtolower($value);

        if (str_starts_with($lowerValue, $normalizedPrefix.'/')) {
            $handle = substr($lowerValue, strlen($normalizedPrefix) + 1);

            return self::isValidHandle($handle) ? $normalizedPrefix.'/'.$handle : null;
        }

        if (str_starts_with($lowerValue, '10.')) {
            return null;
        }

        return self::isValidHandle($value)
            ? $normalizedPrefix.'/'.strtolower($value)
            : null;
    }

    public static function normalizeDoi(string $doi, ?string $prefix = null): ?string
    {
        return self::normalizeInputToDoi($doi, $prefix);
    }

    public static function doiFromHandle(string $handle, ?string $prefix = null): string
    {
        return self::prefix($prefix).'/'.strtolower(trim($handle));
    }

    public static function handleFromDoi(string $doi, ?string $prefix = null): ?string
    {
        $normalizedDoi = self::normalizeInputToDoi($doi, $prefix);
        if ($normalizedDoi === null) {
            return null;
        }

        return strtoupper(substr($normalizedDoi, strlen(self::prefix($prefix)) + 1));
    }

    public static function isValidHandle(string $handle): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/', trim($handle));
    }

    private static function prefix(?string $prefix = null): string
    {
        $configuredPrefix = $prefix ?? (string) config('datacite.production.igsn_prefix', '10.60510');

        return strtolower(trim($configuredPrefix));
    }
}
