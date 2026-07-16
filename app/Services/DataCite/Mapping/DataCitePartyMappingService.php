<?php

declare(strict_types=1);

namespace App\Services\DataCite\Mapping;

use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\RorLookupService;

final readonly class DataCitePartyMappingService
{
    public function __construct(private RorLookupService $rorLookup) {}

    public function formatPersonName(Person $person): string
    {
        if ($person->family_name && $person->given_name) {
            return "{$person->family_name}, {$person->given_name}";
        }

        if ($person->family_name) {
            return $person->family_name;
        }

        if ($person->given_name) {
            return $person->given_name;
        }

        return 'Unknown';
    }

    public function formatInstitutionName(Institution $institution): string
    {
        return $institution->name ?? 'Unknown Institution';
    }

    /**
     * @return array{nameIdentifier: string, nameIdentifierScheme: string, schemeUri: string}|null
     */
    public function buildPersonNameIdentifier(Person $person): ?array
    {
        if (! $person->name_identifier) {
            return null;
        }

        $scheme = $person->name_identifier_scheme ?? 'ORCID';

        return [
            'nameIdentifier' => $person->name_identifier,
            'nameIdentifierScheme' => $scheme,
            'schemeUri' => $this->getSchemeUri($scheme),
        ];
    }

    /**
     * @return array{nameIdentifier: string, nameIdentifierScheme: string, schemeUri: string}|null
     */
    public function buildInstitutionNameIdentifier(Institution $institution): ?array
    {
        if (! $institution->name_identifier) {
            return null;
        }

        $scheme = $institution->name_identifier_scheme ?? 'ROR';

        return [
            'nameIdentifier' => $institution->name_identifier,
            'nameIdentifierScheme' => $scheme,
            'schemeUri' => $this->getSchemeUri($scheme),
        ];
    }

    public function getSchemeUri(string $scheme): string
    {
        return match (strtoupper($scheme)) {
            'ORCID' => 'https://orcid.org/',
            'ROR' => 'https://ror.org/',
            'ISNI' => 'https://isni.org/',
            'GRID' => 'https://www.grid.ac/',
            default => '',
        };
    }

    /**
     * @return array<string, string|null>
     */
    public function transformAffiliation(Affiliation $affiliation): array
    {
        $name = $affiliation->name;

        if ($affiliation->identifier && preg_match('#^https?://ror\.org/[a-z0-9]+/?$#i', $name)) {
            $name = $this->rorLookup->resolve($name) ?? '';
        }

        $data = [
            'name' => $name,
        ];

        if ($affiliation->identifier) {
            $data['affiliationIdentifier'] = $affiliation->identifier;

            $scheme = $affiliation->identifier_scheme ?? 'ROR';
            $data['affiliationIdentifierScheme'] = $scheme;

            $schemeUri = $affiliation->scheme_uri ?? $this->getSchemeUri($scheme);

            if ($schemeUri !== '') {
                $data['schemeUri'] = $schemeUri;
            }
        }

        return $data;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function transformAffiliations(ResourceCreator|ResourceContributor $author): array
    {
        $affiliations = [];

        foreach ($author->affiliations as $affiliation) {
            $affiliations[] = $this->transformAffiliation($affiliation);
        }

        return $affiliations;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPersonCreatorData(ResourceCreator|ResourceContributor $author, Person $person): array
    {
        $data = [
            'name' => $this->formatPersonName($person),
            'nameType' => 'Personal',
        ];

        if ($person->given_name) {
            $data['givenName'] = $person->given_name;
        }

        if ($person->family_name) {
            $data['familyName'] = $person->family_name;
        }

        if ($nameIdentifier = $this->buildPersonNameIdentifier($person)) {
            $data['nameIdentifiers'] = [$nameIdentifier];
        }

        $affiliations = $this->transformAffiliations($author);
        if ($affiliations !== []) {
            $data['affiliation'] = $affiliations;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildInstitutionCreatorData(ResourceCreator|ResourceContributor $author, Institution $institution): array
    {
        $data = [
            'name' => $this->formatInstitutionName($institution),
            'nameType' => 'Organizational',
        ];

        if ($nameIdentifier = $this->buildInstitutionNameIdentifier($institution)) {
            $data['nameIdentifiers'] = [$nameIdentifier];
        }

        $affiliations = $this->transformAffiliations($author);
        if ($affiliations !== []) {
            $data['affiliation'] = $affiliations;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPersonContributorData(ResourceContributor $contributor, Person $person, ?string $contributorType = null): array
    {
        $data = $this->buildPersonCreatorData($contributor, $person);
        $data['contributorType'] = $contributorType ?? $this->resolveContributorType($contributor);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildInstitutionContributorData(ResourceContributor $contributor, Institution $institution, ?string $contributorType = null): array
    {
        $data = $this->buildInstitutionCreatorData($contributor, $institution);
        $data['contributorType'] = $contributorType ?? $this->resolveContributorType($contributor);

        return $data;
    }

    private function resolveContributorType(ResourceContributor $contributor): string
    {
        $firstType = $contributor->contributorTypes->first();

        // @phpstan-ignore nullsafe.neverNull (collection may be empty at runtime for legacy data)
        return $firstType?->slug ?? 'Other';
    }
}
