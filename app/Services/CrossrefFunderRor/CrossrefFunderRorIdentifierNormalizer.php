<?php

declare(strict_types=1);

namespace App\Services\CrossrefFunderRor;

final class CrossrefFunderRorIdentifierNormalizer
{
    public const string CROSSREF_SCHEME_URI = 'https://doi.org/10.13039/';

    public const string ROR_SCHEME_URI = 'https://ror.org/';

    /**
     * @return array{identifier: string, normalized: string, canonical: string}|null
     */
    public function normalizeCrossrefFunderId(?string $identifier, bool $allowBareSuffix = false): ?array
    {
        $value = $this->filledString($identifier);

        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            $value = trim(substr($value, 1, -1));
        }

        if ($value === '' || preg_match('/[\s?#]/', $value)) {
            return null;
        }

        $doiBody = null;

        if (preg_match('#^(?:https?://)?(?:dx\.)?doi\.org/(10\.13039/[0-9]+)$#i', $value, $matches)) {
            $doiBody = $matches[1];
        } elseif (preg_match('#^doi:(10\.13039/[0-9]+)$#i', $value, $matches)) {
            $doiBody = $matches[1];
        } elseif (preg_match('#^(10\.13039/[0-9]+)$#i', $value, $matches)) {
            $doiBody = $matches[1];
        } elseif ($allowBareSuffix && preg_match('/^[0-9]+$/', $value)) {
            $doiBody = '10.13039/'.$value;
        }

        if ($doiBody === null || ! str_starts_with(strtolower($doiBody), '10.13039/')) {
            return null;
        }

        $suffix = substr($doiBody, strlen('10.13039/'));

        if ($suffix === '' || ! preg_match('/^[0-9]+$/', $suffix)) {
            return null;
        }

        return [
            'identifier' => self::CROSSREF_SCHEME_URI.$suffix,
            'normalized' => $suffix,
            'canonical' => self::CROSSREF_SCHEME_URI.$suffix,
        ];
    }

    public function canonicalRorIdentifier(?string $identifier): ?string
    {
        $value = $this->filledString($identifier);

        if ($value === null) {
            return null;
        }

        if (preg_match('#^(?:https?://)?(?:www\.)?ror\.org/([a-z0-9]{9})/?$#i', $value, $matches)) {
            return self::ROR_SCHEME_URI.strtolower($matches[1]);
        }

        if (preg_match('/^[a-z0-9]{9}$/i', $value)) {
            return self::ROR_SCHEME_URI.strtolower($value);
        }

        return null;
    }

    public function filledString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
