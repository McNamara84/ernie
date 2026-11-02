<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SWHID 1.2 with ERNIE's strict qualifier profile. See the shared TS/PHP fixtures.
 *
 * @phpstan-type ParsedSwhid array{normalized: string, core: string, objectType: string, qualifiers: array<string, string>}
 * @phpstan-type SwhidError array{code: string, field: string, message: string}
 * @phpstan-type SwhidValidation array{valid: bool, normalized: string, parsed: ParsedSwhid|null, errors: list<SwhidError>}
 */
final class Swhid
{
    public const PROFILE = 'ernie-swhid-v1';

    public const RESOLVER = 'https://archive.softwareheritage.org/';

    private const TYPES = ['cnt', 'dir', 'rev', 'rel', 'snp'];

    private const QUALIFIERS = ['origin', 'visit', 'anchor', 'path', 'lines', 'bytes'];

    private const RESOLVER_PREFIX = '~^https?://(?:archive\.softwareheritage\.org/(?:browse/)?|(?:identifiers\.org|n2t\.net)/)(?=swh:)~i';

    private const CORE = '~^swh:1:(cnt|dir|rev|rel|snp):[0-9a-f]{40}\z~';

    private const INVALID_IRI_CHARACTER = '~[\s\x{0000}-\x{0020}\x{007f}-\x{009f}<>"\\\\^`{|}]|%(?![0-9a-f]{2})~iu';

    private const IRI_PART = "(?:[A-Za-z0-9._\\~!$&'()*+,=:@%/-]|[^\\x{0000}-\\x{00a0}])";

    public static function normalize(string $value): string
    {
        $space = '[\x{0009}-\x{000d}\x{0020}\x{0085}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]';
        $bare = preg_replace('~^'.$space.'+|'.$space.'+\z~u', '', $value) ?? $value;
        $bare = preg_replace(self::RESOLVER_PREFIX, '', $bare) ?? $bare;
        $parts = explode(';', $bare, 2);
        $core = preg_match('~^swh:~i', $parts[0]) === 1 ? strtolower($parts[0]) : $parts[0];
        if (count($parts) === 1 && preg_match('~^swh:1:(?:cnt|dir|rev|rel|snp):[0-9a-f]{40}/\z~', $core) === 1) {
            $core = substr($core, 0, -1);
        }

        return $core.(isset($parts[1]) ? ';'.$parts[1] : '');
    }

    public static function isCandidate(string $value): bool
    {
        return preg_match('~^swh:~i', self::normalize($value)) === 1;
    }

    /** @return SwhidValidation */
    public static function validate(string $value): array
    {
        $normalized = self::normalize($value);
        $parts = explode(';', $normalized);
        $core = array_shift($parts);
        if (preg_match(self::CORE, $core, $match) !== 1) {
            $components = explode(':', $core);
            if ($components[0] === 'swh' && ($components[1] ?? '') !== '1') {
                return self::failure($normalized, 'unsupported_version', 'identifier', 'Invalid SWHID version. Expected swh:1.');
            }
            if ($components[0] === 'swh' && ! in_array($components[2] ?? '', self::TYPES, true)) {
                return self::failure($normalized, 'unsupported_object_type', 'identifier', 'Invalid SWHID object type. Expected cnt, dir, rev, rel or snp.');
            }

            return self::failure($normalized, 'invalid_core', 'identifier', 'Invalid SWHID. Expected swh:1:<object type>:<40 hexadecimal characters>, optionally followed by qualifiers.');
        }
        $objectType = $match[1];
        $qualifiers = [];
        foreach ($parts as $part) {
            $pair = explode('=', $part, 2);
            $key = $pair[0];
            if (! in_array($key, self::QUALIFIERS, true)) {
                return self::failure($normalized, 'unknown_qualifier', 'qualifiers.'.$key, 'Unknown SWHID qualifier. Expected origin, visit, anchor, path, lines or bytes.');
            }
            if (array_key_exists($key, $qualifiers)) {
                return self::failure($normalized, 'duplicate_qualifier', 'qualifiers.'.$key, "SWHID qualifier {$key} must occur only once.");
            }
            $qualifierValue = $pair[1] ?? '';
            if ($qualifierValue === '' || preg_match(self::INVALID_IRI_CHARACTER, $qualifierValue) !== 0) {
                return self::failure($normalized, 'invalid_qualifier_value', 'qualifiers.'.$key, "Invalid SWHID {$key} value. Use a non-empty value with valid percent encoding and no whitespace.");
            }
            $qualifiers[$key] = $qualifierValue;
        }
        foreach (['origin', 'path', 'visit', 'anchor'] as $key) {
            if (! isset($qualifiers[$key])) {
                continue;
            }
            $qualifierValue = $qualifiers[$key];
            $valid = match ($key) {
                'origin' => self::isOrigin($qualifierValue),
                'path' => preg_match('~^(?:/|%2f)~i', $qualifierValue) === 1 && preg_match('~^'.self::IRI_PART.'+\z~u', $qualifierValue) === 1,
                'visit' => preg_match('~^swh:1:snp:[0-9a-f]{40}\z~', $qualifierValue) === 1,
                'anchor' => preg_match('~^swh:1:(?:dir|rev|rel|snp):[0-9a-f]{40}\z~', $qualifierValue) === 1,
            };
            if (! $valid) {
                $expected = match ($key) {
                    'origin' => 'an absolute URI/IRI', 'path' => 'an absolute path',
                    'visit' => 'a snapshot core SWHID', 'anchor' => 'a directory, commit, release or snapshot core SWHID',
                };

                return self::failure($normalized, 'invalid_qualifier_value', 'qualifiers.'.$key, "Invalid SWHID {$key}. Expected {$expected}.");
            }
        }
        foreach (['visit' => 'origin', 'anchor' => 'path'] as $key => $dependency) {
            if (isset($qualifiers[$key]) && ! isset($qualifiers[$dependency])) {
                return self::failure($normalized, 'missing_qualifier_dependency', 'qualifiers.'.$key, "SWHID qualifier {$key} requires {$dependency}.");
            }
        }
        if (isset($qualifiers['lines'], $qualifiers['bytes'])) {
            return self::failure($normalized, 'conflicting_fragment_qualifiers', 'qualifiers', 'Use either lines or bytes, not both.');
        }
        foreach (['lines', 'bytes'] as $key) {
            if (! isset($qualifiers[$key])) {
                continue;
            }
            if ($objectType !== 'cnt') {
                return self::failure($normalized, 'invalid_qualifier_value', 'qualifiers.'.$key, "SWHID qualifier {$key} is only valid for files (cnt).");
            }
            if (! self::validRange($qualifiers[$key], $key === 'lines')) {
                $minimum = $key === 'lines' ? '1' : '0';

                return self::failure($normalized, 'invalid_range', 'qualifiers.'.$key, "Invalid SWHID {$key}. Expected N or N-M, starting at {$minimum}, with N no greater than M.");
            }
        }

        return ['valid' => true, 'normalized' => $normalized, 'parsed' => [
            'normalized' => $normalized, 'core' => $core, 'objectType' => $objectType, 'qualifiers' => $qualifiers,
        ], 'errors' => []];
    }

    /** @return ParsedSwhid|null */
    public static function parse(string $value): ?array
    {
        return self::validate($value)['parsed'];
    }

    public static function isValid(string $value): bool
    {
        return self::validate($value)['valid'];
    }

    public static function resolveUrl(string $value): ?string
    {
        $parsed = self::parse($value);
        if ($parsed === null) {
            return null;
        }

        // Encode the URL transport layer, including existing SWHID percent escapes.
        // Software Heritage keeps :;=/ readable in resolver paths; ? and # are data.
        $path = strtr(rawurlencode($parsed['normalized']), [
            '%3A' => ':', '%3B' => ';', '%3D' => '=', '%2F' => '/',
        ]);

        return self::RESOLVER.$path;
    }

    public static function comparisonKey(string $value): ?string
    {
        $parsed = self::parse($value);
        if ($parsed === null) {
            return null;
        }
        $qualifiers = $parsed['qualifiers'];
        ksort($qualifiers);
        $key = $parsed['core'];
        foreach ($qualifiers as $name => $qualifier) {
            $key .= ';'.$name.'='.bin2hex(rawurldecode($qualifier));
        }

        return $key;
    }

    private static function isOrigin(string $value): bool
    {
        if (preg_match('~^([A-Za-z][A-Za-z0-9+.-]*):(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:\#(.*))?\z~u', $value, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return false;
        }
        [, $scheme, $authority, $path] = $match;
        if (preg_match('~^'.self::IRI_PART.'*\z~u', $path) !== 1) {
            return false;
        }
        foreach ([$match[4] ?? '', $match[5] ?? ''] as $part) {
            if (preg_match('~^(?:'.self::IRI_PART.'|[?])*\z~u', $part) !== 1) {
                return false;
            }
        }
        $host = null;
        if ($authority !== null) {
            if (preg_match('~^(?:[^\s/?#@\[\]]*@)?(\[[^\[\]]+\]|[^\s:/?#@\[\]]*)(?::[0-9]*)?\z~u', $authority, $hostMatch) !== 1) {
                return false;
            }
            $host = $hostMatch[1];
            if (str_starts_with($host, '[')) {
                $literal = substr($host, 1, -1);
                if (preg_match("~^v[0-9a-f]+\\.[a-z0-9._\\~!$&'()*+,=:-]+\\z~i", $literal) !== 1
                    && filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    return false;
                }
            }
        }

        return preg_match('~^(?:https?|ftp)\z~i', $scheme) !== 1 || ($host !== null && $host !== '');
    }

    private static function validRange(string $value, bool $lines): bool
    {
        if (preg_match('~^[0-9]+(?:-[0-9]+)?\z~', $value) !== 1) {
            return false;
        }
        $parts = array_map(static fn (string $part): string => ltrim($part, '0') ?: '0', explode('-', $value));
        $start = $parts[0];
        $end = $parts[1] ?? $start;

        return ! ($lines && $start === '0') && (strlen($start) < strlen($end) || (strlen($start) === strlen($end) && strcmp($start, $end) <= 0));
    }

    /** @return SwhidValidation */
    private static function failure(string $normalized, string $code, string $field, string $message): array
    {
        return ['valid' => false, 'normalized' => $normalized, 'parsed' => null, 'errors' => [['code' => $code, 'field' => $field, 'message' => $message]]];
    }
}
