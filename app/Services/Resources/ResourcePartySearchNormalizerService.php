<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Models\Institution;
use App\Models\Person;

final class ResourcePartySearchNormalizerService
{
    /** @return list<string> */
    public function queryTerms(string $query): array
    {
        $literal = $this->literal($query);

        if ($literal === '') {
            return [];
        }

        if (str_contains($literal, '@')) {
            return [$literal];
        }

        $name = $this->name($literal);

        return $this->unique([
            $literal,
            $name,
            str_replace(' ', '', $name),
        ]);
    }

    /** @return list<string> */
    public function entityTerms(Person|Institution $party): array
    {
        if ($party instanceof Institution) {
            $name = $this->name($party->name);

            return $this->unique([$name, str_replace(' ', '', $name)]);
        }

        return $this->personNameTerms($party->given_name, $party->family_name);
    }

    /** @return list<string> */
    public function personNameTerms(?string $givenName, ?string $familyName, ?string $fullName = null): array
    {
        $givenName = $this->name((string) $givenName);
        $familyName = $this->name((string) $familyName);
        $fullName = $this->name((string) $fullName);
        $compactGivenName = str_replace(' ', '', $givenName);
        $compactFamilyName = str_replace(' ', '', $familyName);

        return $this->unique([
            $fullName,
            str_replace(' ', '', $fullName),
            $givenName,
            $familyName,
            trim($givenName.' '.$familyName),
            trim($familyName.' '.$givenName),
            $compactGivenName.$compactFamilyName,
            $compactFamilyName.$compactGivenName,
        ]);
    }

    public function personNameMatches(?string $givenName, ?string $familyName, ?string $fullName, string $query): bool
    {
        if (str_contains($query, '@')) {
            return false;
        }

        foreach ($this->queryTerms($query) as $queryTerm) {
            foreach ($this->personNameTerms($givenName, $familyName, $fullName) as $partyTerm) {
                if (str_contains($partyTerm, $queryTerm)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    public function emailTerms(?string $email): array
    {
        $email = $this->literal((string) $email);

        return $email === '' ? [] : [$email];
    }

    public function partyMatches(Person|Institution $party, string $query): bool
    {
        if (str_contains($query, '@')) {
            return false;
        }

        foreach ($this->queryTerms($query) as $queryTerm) {
            foreach ($this->entityTerms($party) as $partyTerm) {
                if (str_contains($partyTerm, $queryTerm)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function emailMatches(?string $email, string $query): bool
    {
        $query = $this->literal($query);
        $email = $this->literal((string) $email);

        return $query !== '' && $email !== '' && str_contains($email, $query);
    }

    public function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }

    private function literal(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function name(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $this->literal($value)));
    }

    /**
     * @param  array<int, string>  $terms
     * @return list<string>
     */
    private function unique(array $terms): array
    {
        return array_values(array_unique(array_filter(
            $terms,
            static fn (string $term): bool => $term !== '',
        )));
    }
}
