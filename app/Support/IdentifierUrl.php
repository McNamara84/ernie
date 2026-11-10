<?php

declare(strict_types=1);

namespace App\Support;

/** Server-side counterpart of LandingPages/lib/resolveIdentifierUrl.ts. */
final class IdentifierUrl
{
    public static function http(?string $value): ?string
    {
        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true)
            && ! preg_match('/[\x00-\x20<>"\\\\]/', $value) ? $value : null;
    }

    public static function doi(?string $value): ?string
    {
        $value = preg_replace('~^https?://(?:dx\.)?doi\.org/?~i', '', trim((string) $value)) ?? '';
        $value = preg_replace('/^doi:\s*/i', '', $value) ?? '';

        return preg_match('~^10\.\d{4,}(?:\.\d+)*/[^\s<>"\\\\]+$~uD', $value) === 1 ? $value : null;
    }

    public static function resolve(?string $value, string $type): ?string
    {
        $id = trim((string) $value);
        if ($type === 'DOI') {
            $doi = self::doi($id);

            return $doi === null ? null : 'https://doi.org/'.$doi;
        }
        if ($id === '' || preg_match('/[\x00-\x20<>"\\\\]/', $id)) {
            return null;
        }
        if ($type === 'IGSN') {
            $id = preg_replace('~^https?://(?:(?:www\.)?igsn\.org|hdl\.handle\.net)/?~i', '', $id) ?? '';
            if (($doi = self::doi($id)) !== null) {
                return 'https://doi.org/'.$doi;
            }

            return preg_match('~^10273/[A-Za-z0-9][A-Za-z0-9._-]*$~D', $id) ? 'https://hdl.handle.net/'.$id
                : (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,}$/D', $id) ? 'https://igsn.org/'.$id : null);
        }

        return match ($type) {
            'URL' => self::http($id),
            'Handle' => ($handle = preg_replace('~^https?://hdl\.handle\.net/?~i', '', $id)) !== '' ? 'https://hdl.handle.net/'.$handle : null,
            'arXiv' => 'https://arxiv.org/abs/'.$id,
            'ISBN' => 'https://search.worldcat.org/isbn/'.$id,
            'ISSN' => 'https://portal.issn.org/resource/ISSN/'.$id,
            'URN' => 'https://nbn-resolving.org/'.$id,
            'RAiD' => 'https://raid.org/'.$id,
            'SWHID' => Swhid::resolveUrl($id),
            default => null,
        };
    }
}
