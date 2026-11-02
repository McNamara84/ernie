<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolve identities against the complete party list, so an identifierless
 * name cannot bridge two different ORCIDs depending on input order.
 */
final class PersonIdentityMatcher
{
    /** @var array<string, array<string, true>> */
    private array $orcidsByName = [];

    /** @param array<int, array<string, mixed>> $people */
    public function __construct(array $people)
    {
        foreach ($people as $person) {
            $name = self::nameKey($person);
            $orcid = self::orcid($person);
            if ($name !== null && $orcid !== null) {
                $this->orcidsByName[$name][$orcid] = true;
            }
        }
    }

    /** @param array<string, mixed> $person */
    public function key(array $person): ?string
    {
        if (($person['type'] ?? 'person') !== 'person') {
            return null;
        }

        $orcid = $this->resolvedOrcid($person);
        if ($orcid !== null) {
            return 'orcid:'.$orcid;
        }

        $name = self::nameKey($person);

        return $name !== null ? 'name:'.$name : null;
    }

    /** @param array<string, mixed> $person */
    public function resolvedOrcid(array $person): ?string
    {
        $orcid = self::orcid($person);
        if ($orcid !== null) {
            return $orcid;
        }

        $name = self::nameKey($person);
        $candidates = $name !== null ? ($this->orcidsByName[$name] ?? []) : [];

        return count($candidates) === 1 ? array_key_first($candidates) : null;
    }

    /** @param array<string, mixed> $person */
    public function hasConflictingOrcids(array $person): bool
    {
        $name = self::nameKey($person);

        return $name !== null && count($this->orcidsByName[$name] ?? []) > 1;
    }

    /** @param array<string, mixed> $person */
    public static function orcid(array $person): ?string
    {
        if (($person['type'] ?? 'person') !== 'person' || ! is_string($person['orcid'] ?? null)) {
            return null;
        }

        $orcid = strtoupper(trim(OrcidNormalizer::extractBareId($person['orcid']), '/'));

        return OrcidNormalizer::isValidFormat($orcid) ? $orcid : null;
    }

    /** @param array<string, mixed> $person */
    public static function nameKey(array $person): ?string
    {
        if (($person['type'] ?? 'person') !== 'person') {
            return null;
        }

        $parts = [];
        foreach (['lastName', 'firstName'] as $field) {
            $value = $person[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
            $parts[] = mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
        }

        return implode(', ', $parts);
    }
}
