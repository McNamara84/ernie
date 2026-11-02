<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\RorLookupService;
use App\Support\MslLaboratoryService;
use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<contributors>/<contributor>` into the editor payload, also
 * extracting MSL laboratories (HostingInstitution + labid) separately.
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
        private RorLookupService $rorLookupService,
    ) {}

    /**
     * @return array{contributors: array<int, array<string, mixed>>, mslLaboratories: array<int, array<string, string|null>>}
     */
    public function parse(XmlReader $reader): array
    {
        $contributorElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="contributors"]/*[local-name()="contributor"]')
            ->get();

        $contributors = [];
        $mslLaboratories = [];

        foreach ($contributorElements as $contributor) {
            $content = $contributor->getContent();

            if (! is_array($content)) {
                continue;
            }

            $contributorType = $contributor->getAttribute('contributorType');
            $roles = $this->extractContributorRoles($contributorType);

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

                $contributors[] = $contributorData;

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

            $contributors[] = $contributorData;
        }

        return [
            'contributors' => $contributors,
            'mslLaboratories' => $mslLaboratories,
        ];
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

            if (is_string($scheme) && Str::lower(trim($scheme)) === 'labid') {
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
     * @return array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string|null}|null
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
            $isRor = (is_string($affiliationScheme) && strcasecmp(trim($affiliationScheme), 'ROR') === 0) ||
                     str_contains(strtolower($affiliationIdentifier), 'ror.org');

            if ($isRor) {
                $affiliationRor = $this->rorLookupService->canonicalise($affiliationIdentifier);

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
