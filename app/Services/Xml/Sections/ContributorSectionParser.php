<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\MslLaboratoryService;
use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<contributors>/<contributor>` into the editor payload, also
 * extracting MSL laboratories (HostingInstitution + labid) and contact
 * persons (ContactPerson) into separate buckets.
 */
final readonly class ContributorSectionParser
{
    /**
     * @var array<string, string>
     */
    private const CONTRIBUTOR_ROLE_LABELS = [
        'contactperson' => 'Contact Person',
        'datacollector' => 'Data Collector',
        'datacurator' => 'Data Curator',
        'datamanager' => 'Data Manager',
        'distributor' => 'Distributor',
        'editor' => 'Editor',
        'hostinginstitution' => 'Hosting Institution',
        'producer' => 'Producer',
        'projectleader' => 'Project Leader',
        'projectmanager' => 'Project Manager',
        'projectmember' => 'Project Member',
        'registrationagency' => 'Registration Agency',
        'registrationauthority' => 'Registration Authority',
        'relatedperson' => 'Related Person',
        'researcher' => 'Researcher',
        'researchgroup' => 'Research Group',
        'rightsholder' => 'Rights Holder',
        'sponsor' => 'Sponsor',
        'supervisor' => 'Supervisor',
        'translator' => 'Translator',
        'workpackageleader' => 'Work Package Leader',
        'other' => 'Other',
    ];

    /**
     * @var string[]
     *
     * @see resources/js/lib/contributors.ts INSTITUTION_ONLY_ROLE_KEY_VALUES
     */
    private const INSTITUTION_ONLY_CONTRIBUTOR_ROLE_KEYS = [
        'distributor',
        'hostinginstitution',
        'registrationagency',
        'registrationauthority',
        'researchgroup',
        'sponsor',
    ];

    public function __construct(
        private AuthorSectionParser $authorSectionParser,
        private MslLaboratoryService $mslLaboratoryService,
    ) {}

    /**
     * @return array{contributors: array<int, array<string, mixed>>, mslLaboratories: array<int, array<string, string>>, contactPersons: array<int, array<string, mixed>>}
     */
    public function parse(XmlReader $reader): array
    {
        $contributorElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="contributors"]/*[local-name()="contributor"]')
            ->get();

        $contributors = [];
        $contributorIndexByKey = [];
        $mslLaboratories = [];
        $contactPersons = [];

        foreach ($contributorElements as $contributor) {
            $content = $contributor->getContent();

            if (! is_array($content)) {
                continue;
            }

            $contributorType = $contributor->getAttribute('contributorType');
            $roles = $this->extractContributorRoles($contributorType);

            if (strcasecmp($contributorType ?? '', 'ContactPerson') === 0) {
                $contactPerson = $this->extractContactPersonData($content);
                if ($contactPerson !== null) {
                    $contactPersons[] = $contactPerson;
                }

                continue;
            }

            $labId = $this->extractLabId($content);
            if ($labId !== null && strcasecmp($contributorType ?? '', 'HostingInstitution') === 0) {
                Log::info('Extracting MSL Laboratory', [
                    'labId' => $labId,
                    'contributorType' => $contributorType,
                ]);

                $mslLab = $this->extractMslLaboratory($content, $labId);

                if ($mslLab !== null) {
                    Log::info('MSL Laboratory extracted successfully', $mslLab);
                    $mslLaboratories[] = $mslLab;
                } else {
                    Log::warning('MSL Laboratory extraction returned null', [
                        'labId' => $labId,
                    ]);
                }

                continue;
            }

            $nameElement = XmlElementHelpers::firstElementByKey($content, 'contributorName');
            $nameType = $nameElement?->getAttribute('nameType');

            $isInstitution = $this->isInstitutionContributor($nameType, $roles);

            if ($isInstitution) {
                $institutionName = XmlElementHelpers::stringValue($nameElement) ?? '';
                $affiliations = $this->extractInstitutionContributorAffiliations($content, $institutionName);

                $contributorData = [
                    'type' => 'institution',
                    'institutionName' => $institutionName,
                    'roles' => $roles,
                    'affiliations' => $affiliations,
                ];

                $contributors = $this->storeContributor(
                    $contributors,
                    $contributorIndexByKey,
                    $contributorData,
                );

                continue;
            }

            $givenName = XmlElementHelpers::stringValue(XmlElementHelpers::firstElementByKey($content, 'givenName'));
            $familyName = XmlElementHelpers::stringValue(XmlElementHelpers::firstElementByKey($content, 'familyName'));

            if ((! $givenName || ! $familyName) && $nameElement instanceof Element) {
                $resolved = XmlElementHelpers::splitCreatorName(XmlElementHelpers::stringValue($nameElement));
                $familyName ??= $resolved['familyName'];
                $givenName ??= $resolved['givenName'];
            }

            $fallbackLastName = $familyName ?? (XmlElementHelpers::stringValue($nameElement) ?? '');

            $contributorData = [
                'type' => 'person',
                'roles' => $roles,
                'orcid' => $this->authorSectionParser->extractOrcid($content),
                'firstName' => $givenName ?? '',
                'lastName' => $fallbackLastName,
                'affiliations' => $this->authorSectionParser->extractAffiliations($content),
            ];

            $contributors = $this->storeContributor(
                $contributors,
                $contributorIndexByKey,
                $contributorData,
            );
        }

        return [
            'contributors' => $contributors,
            'mslLaboratories' => $mslLaboratories,
            'contactPersons' => $contactPersons,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null
     */
    private function extractContactPersonData(array $content): ?array
    {
        $nameElement = XmlElementHelpers::firstElementByKey($content, 'contributorName');
        $nameType = $nameElement?->getAttribute('nameType');

        if (is_string($nameType) && Str::lower($nameType) === 'organizational') {
            return null;
        }

        $givenName = XmlElementHelpers::stringValue(XmlElementHelpers::firstElementByKey($content, 'givenName'));
        $familyName = XmlElementHelpers::stringValue(XmlElementHelpers::firstElementByKey($content, 'familyName'));

        if ((! $givenName || ! $familyName) && $nameElement instanceof Element) {
            $resolved = XmlElementHelpers::splitCreatorName(XmlElementHelpers::stringValue($nameElement));
            $familyName ??= $resolved['familyName'];
            $givenName ??= $resolved['givenName'];
        }

        if (! $familyName) {
            return null;
        }

        return [
            'type' => 'person',
            'orcid' => $this->authorSectionParser->extractOrcid($content),
            'firstName' => $givenName ?? '',
            'lastName' => $familyName,
            'affiliations' => $this->authorSectionParser->extractAffiliations($content),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $contributors
     * @param  array<string, int>  $indexByKey
     * @param  array<string, mixed>  $contributor
     * @return array<int, array<string, mixed>>
     */
    private function storeContributor(
        array $contributors,
        array &$indexByKey,
        array $contributor,
    ): array {
        $keys = $this->buildContributorAggregationKeys($contributor);

        foreach ($keys as $key) {
            if (! array_key_exists($key, $indexByKey)) {
                continue;
            }

            $existingIndex = $indexByKey[$key];
            $contributors[$existingIndex] = $this->mergeContributorEntries(
                $contributors[$existingIndex],
                $contributor,
            );

            foreach ($keys as $aliasKey) {
                $indexByKey[$aliasKey] = $existingIndex;
            }

            return $contributors;
        }

        $contributors[] = $contributor;

        if (! empty($keys)) {
            $index = count($contributors) - 1;

            foreach ($keys as $key) {
                $indexByKey[$key] = $index;
            }
        }

        return $contributors;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeContributorEntries(array $primary, array $incoming): array
    {
        if (($primary['type'] ?? null) !== ($incoming['type'] ?? null)) {
            return $primary;
        }

        $primary['roles'] = $this->mergeContributorRoles(
            is_array($primary['roles'] ?? null) ? $primary['roles'] : [],
            is_array($incoming['roles'] ?? null) ? $incoming['roles'] : [],
        );

        if (($primary['type'] ?? null) === 'person') {
            return $this->mergePersonContributor($primary, $incoming);
        }

        if (($primary['type'] ?? null) === 'institution') {
            return $this->mergeInstitutionContributor($primary, $incoming);
        }

        return $primary;
    }

    /**
     * @param  array<int, mixed>  $existing
     * @param  array<int, mixed>  $incoming
     * @return array<int, string>
     */
    private function mergeContributorRoles(array $existing, array $incoming): array
    {
        foreach ($incoming as $role) {
            if (! is_string($role) || $role === '') {
                continue;
            }

            if (! in_array($role, $existing, true)) {
                $existing[] = $role;
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergePersonContributor(array $primary, array $incoming): array
    {
        if (($primary['orcid'] ?? '') === '' && isset($incoming['orcid']) && is_string($incoming['orcid'])) {
            $primary['orcid'] = $incoming['orcid'];
        }

        foreach (['firstName', 'lastName'] as $field) {
            if (
                (! isset($primary[$field]) || ! is_string($primary[$field]) || trim($primary[$field]) === '')
                && isset($incoming[$field])
                && is_string($incoming[$field])
                && trim($incoming[$field]) !== ''
            ) {
                $primary[$field] = $incoming[$field];
            }
        }

        $primary['affiliations'] = $this->mergeAffiliations(
            is_array($primary['affiliations'] ?? null) ? $primary['affiliations'] : [],
            is_array($incoming['affiliations'] ?? null) ? $incoming['affiliations'] : [],
        );

        return $primary;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeInstitutionContributor(array $primary, array $incoming): array
    {
        if (
            (! isset($primary['institutionName'])
                || ! is_string($primary['institutionName'])
                || trim($primary['institutionName']) === '')
            && isset($incoming['institutionName'])
            && is_string($incoming['institutionName'])
            && trim($incoming['institutionName']) !== ''
        ) {
            $primary['institutionName'] = $incoming['institutionName'];
        }

        $primary['affiliations'] = $this->mergeAffiliations(
            is_array($primary['affiliations'] ?? null) ? $primary['affiliations'] : [],
            is_array($incoming['affiliations'] ?? null) ? $incoming['affiliations'] : [],
        );

        return $primary;
    }

    /**
     * @param  array<int, mixed>  $existing
     * @param  array<int, mixed>  $incoming
     * @return array<int, array{value: string, rorId: ?string}>
     */
    private function mergeAffiliations(array $existing, array $incoming): array
    {
        $merged = [];
        $seen = [];

        foreach ([$existing, $incoming] as $group) {
            foreach ($group as $affiliation) {
                if (! is_array($affiliation)) {
                    continue;
                }

                $value = isset($affiliation['value']) && is_string($affiliation['value'])
                    ? trim($affiliation['value'])
                    : '';
                $rorId = isset($affiliation['rorId']) && is_string($affiliation['rorId'])
                    ? trim($affiliation['rorId'])
                    : null;

                if ($rorId === '') {
                    $rorId = null;
                }

                if ($value === '' && $rorId === null) {
                    continue;
                }

                if ($rorId !== null) {
                    $key = 'ror:'.Str::lower($rorId);
                } else {
                    $key = 'value:'.Str::lower($value);
                }

                if (isset($seen[$key])) {
                    $existingIndex = $seen[$key];

                    if ($merged[$existingIndex]['value'] === '' && $value !== '') {
                        $merged[$existingIndex]['value'] = $value;
                    }

                    if ($merged[$existingIndex]['rorId'] === null && $rorId !== null) {
                        $merged[$existingIndex]['rorId'] = $rorId;
                    }

                    continue;
                }

                $seen[$key] = count($merged);

                $merged[] = [
                    'value' => $value,
                    'rorId' => $rorId,
                ];
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $contributor
     * @return string[]
     */
    private function buildContributorAggregationKeys(array $contributor): array
    {
        $type = $contributor['type'] ?? null;

        if (! is_string($type)) {
            return [];
        }

        if ($type === 'person') {
            $keys = [];
            $orcid = $this->normaliseIdentifier($contributor['orcid'] ?? null);

            if ($orcid !== null) {
                $keys[] = 'person:orcid:'.$orcid;
            }

            $lastName = $this->normaliseKeyString($contributor['lastName'] ?? null);
            $firstName = $this->normaliseKeyString($contributor['firstName'] ?? null);

            if ($lastName !== null || $firstName !== null) {
                $keys[] = 'person:name:'.($lastName ?? '').':'.($firstName ?? '');
            }

            return $keys;
        }

        if ($type === 'institution') {
            $keys = [];
            $affiliations = is_array($contributor['affiliations'] ?? null)
                ? $contributor['affiliations']
                : [];

            foreach ($affiliations as $affiliation) {
                if (! is_array($affiliation)) {
                    continue;
                }

                $rorId = $this->normaliseIdentifier($affiliation['rorId'] ?? null);

                if ($rorId !== null) {
                    $keys[] = 'institution:ror:'.$rorId;
                }
            }

            $institutionName = $this->normaliseKeyString($contributor['institutionName'] ?? null);

            if ($institutionName !== null) {
                $keys[] = 'institution:name:'.$institutionName;
            }

            return $keys;
        }

        return [];
    }

    private function normaliseIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : Str::lower($trimmed);
    }

    private function normaliseKeyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $normalised = preg_replace('/\s+/u', ' ', $trimmed);

        if (! is_string($normalised)) {
            $normalised = $trimmed;
        }

        return Str::lower($normalised);
    }

    /**
     * @return string[]
     */
    private function extractContributorRoles(?string $rawRoles): array
    {
        if (! is_string($rawRoles)) {
            return [];
        }

        $parts = preg_split('/[;,]/', $rawRoles) ?: [];

        if ($parts === []) {
            $parts = [$rawRoles];
        }

        $roles = [];

        foreach ($parts as $part) {
            $trimmed = trim($part);

            if ($trimmed === '') {
                continue;
            }

            $resolved = $this->resolveContributorRoleName($trimmed);

            if ($resolved === '') {
                continue;
            }

            if (! in_array($resolved, $roles, true)) {
                $roles[] = $resolved;
            }
        }

        return $roles;
    }

    private function resolveContributorRoleName(string $role): string
    {
        $normalisedKey = $this->normaliseContributorRoleKey($role);

        if ($normalisedKey !== null && isset(self::CONTRIBUTOR_ROLE_LABELS[$normalisedKey])) {
            return self::CONTRIBUTOR_ROLE_LABELS[$normalisedKey];
        }

        $headline = Str::headline($role);

        return $headline !== '' ? $headline : trim($role);
    }

    private function normaliseContributorRoleKey(string $role): ?string
    {
        $slug = Str::slug($role);

        if ($slug === '') {
            return null;
        }

        return str_replace('-', '', $slug);
    }

    /**
     * @param  string[]  $roles
     */
    private function isInstitutionContributor(?string $nameType, array $roles): bool
    {
        if (is_string($nameType)) {
            $normalised = Str::lower($nameType);

            if ($normalised === 'organizational') {
                return true;
            }

            if ($normalised === 'personal') {
                return false;
            }
        }

        return $this->contributorRolesRequireInstitution($roles);
    }

    /**
     * @param  string[]  $roles
     */
    private function contributorRolesRequireInstitution(array $roles): bool
    {
        $hasRoles = false;

        foreach ($roles as $role) {
            if ($role === '') {
                continue;
            }

            $hasRoles = true;

            $key = $this->normaliseContributorRoleKey($role);

            if ($key === null) {
                return false;
            }

            if (! in_array($key, self::INSTITUTION_ONLY_CONTRIBUTOR_ROLE_KEYS, true)) {
                return false;
            }
        }

        return $hasRoles;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, array{value: string, rorId: string|null}>
     */
    private function extractInstitutionContributorAffiliations(array $content, string $fallbackLabel): array
    {
        $affiliations = $this->authorSectionParser->extractAffiliations($content);

        if (! empty($affiliations)) {
            return $affiliations;
        }

        $identifierElements = XmlElementHelpers::allElementsByKey($content, 'nameIdentifier');

        foreach ($identifierElements as $element) {
            $scheme = $element->getAttribute('nameIdentifierScheme');
            $value = XmlElementHelpers::stringValue($element);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $resolved = $this->authorSectionParser->resolveAffiliationByRor(
                $value,
                is_string($scheme) ? $scheme : null,
                $fallbackLabel,
            );

            if ($resolved !== null) {
                return [$resolved];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function extractLabId(array $content): ?string
    {
        $identifierElements = XmlElementHelpers::allElementsByKey($content, 'nameIdentifier');

        foreach ($identifierElements as $element) {
            $scheme = $element->getAttribute('nameIdentifierScheme');

            if (is_string($scheme) && Str::lower($scheme) === 'labid') {
                $value = XmlElementHelpers::stringValue($element);

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string}|null
     */
    private function extractMslLaboratory(array $content, string $labId): ?array
    {
        $nameElement = XmlElementHelpers::firstElementByKey($content, 'contributorName');
        $labName = XmlElementHelpers::stringValue($nameElement);

        $affiliationElement = XmlElementHelpers::firstElementByKey($content, 'affiliation');
        $affiliationName = XmlElementHelpers::stringValue($affiliationElement);

        $affiliationRor = null;
        $affiliationIdentifier = $affiliationElement?->getAttribute('affiliationIdentifier');
        $affiliationScheme = $affiliationElement?->getAttribute('affiliationIdentifierScheme');

        Log::debug('Extracting MSL Lab affiliation', [
            'labId' => $labId,
            'affiliationName' => $affiliationName,
            'affiliationIdentifier' => $affiliationIdentifier,
            'affiliationScheme' => $affiliationScheme,
        ]);

        if ($affiliationIdentifier) {
            $isRor = ($affiliationScheme === 'ROR') ||
                     str_contains(strtolower($affiliationIdentifier), 'ror.org');

            if ($isRor) {
                if (str_starts_with($affiliationIdentifier, 'http')) {
                    $affiliationRor = $affiliationIdentifier;
                } else {
                    $affiliationRor = 'https://ror.org/'.$affiliationIdentifier;
                }

                Log::debug('ROR identified and normalized', [
                    'original' => $affiliationIdentifier,
                    'normalized' => $affiliationRor,
                ]);
            }
        }

        $enrichedLab = $this->mslLaboratoryService->enrichLaboratoryData(
            $labId,
            $labName,
            $affiliationName,
            $affiliationRor
        );

        if (empty($enrichedLab['name'])) {
            Log::warning('MSL Laboratory extracted without name', [
                'labId' => $labId,
            ]);

            return null;
        }

        return $enrichedLab;
    }
}
