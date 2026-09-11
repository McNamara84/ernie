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

        $givenName = $this->name((string) $party->given_name);
        $familyName = $this->name((string) $party->family_name);
        $compactGivenName = str_replace(' ', '', $givenName);
        $compactFamilyName = str_replace(' ', '', $familyName);

        return $this->unique([
            $givenName,
            $familyName,
            trim($givenName.' '.$familyName),
            trim($familyName.' '.$givenName),
            $compactGivenName.$compactFamilyName,
            $compactFamilyName.$compactGivenName,
        ]);
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
