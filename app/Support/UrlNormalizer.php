<?php

namespace App\Support;

final class UrlNormalizer
{
    public static function normalizeAppUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        // Fix common misconfiguration where ':' is missing after scheme (e.g. "https//example.org").
        $trimmed = preg_replace('/^(https?)(\/\/)/i', '$1://', $trimmed) ?? $trimmed;

        // Collapse excessive slashes after the scheme (e.g. "https:////example.org").
        $trimmed = preg_replace('/^(https?:)\/{3,}/i', '$1//', $trimmed) ?? $trimmed;

        // If something still contains a duplicated protocol fragment, keep the first one.
        $trimmed = preg_replace('/^(https?:\/\/)(https?:?\/\/)/i', '$1', $trimmed) ?? $trimmed;

        return $trimmed;
    }
}
