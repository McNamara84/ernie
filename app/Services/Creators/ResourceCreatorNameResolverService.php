<?php

declare(strict_types=1);

namespace App\Services\Creators;

use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;

final class ResourceCreatorNameResolverService
{
    /**
     * @return array{name: string, given_name: string|null, family_name: string|null, source: 'snapshot'|'person'}
     */
    public function resolve(ResourceCreator|ResourceContributor $author, Person $person): array
    {
        if ($author instanceof ResourceCreator && $author->hasNameSnapshot()) {
            $givenName = $this->filled($author->given_name_snapshot);
            $familyName = $this->filled($author->family_name_snapshot);
            $name = $this->filled($author->name_snapshot)
                ?? $this->format($familyName, $givenName);

            return [
                'name' => $name ?? 'Unknown',
                'given_name' => $givenName,
                'family_name' => $familyName,
                'source' => 'snapshot',
            ];
        }

        $givenName = $this->filled($person->given_name);
        $familyName = $this->filled($person->family_name);

        return [
            'name' => $this->format($familyName, $givenName) ?? 'Unknown',
            'given_name' => $givenName,
            'family_name' => $familyName,
            'source' => 'person',
        ];
    }

    public function format(?string $familyName, ?string $givenName): ?string
    {
        $familyName = $this->filled($familyName);
        $givenName = $this->filled($givenName);

        if ($familyName !== null && $givenName !== null) {
            return $familyName.', '.$givenName;
        }

        return $familyName ?? $givenName;
    }

    public function filled(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
