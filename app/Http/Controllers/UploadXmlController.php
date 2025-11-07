<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadXmlRequest;
use App\Models\ResourceType;
use App\Support\GcmdUriHelper;
use App\Support\MslLaboratoryService;
use App\Support\XmlKeywordExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

class UploadXmlController extends Controller
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

    /**
     * @var array<string, array{value: string, rorId: string}>
     */
    private array $affiliationMap = [];

    private bool $affiliationMapLoaded = false;

    public function __invoke(UploadXmlRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $contents = $validated['file']->get();

        $reader = XmlReader::fromString($contents);
        $doi = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="identifier" and @identifierType="DOI"]'),
        );
        $year = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="publicationYear"]'),
        );
        $version = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="version"]'),
        );
        $language = $this->extractFirstStringFromQuery(
            $reader->xpathValue('//*[local-name()="language"]'),
        );
        $authors = $this->extractAuthors($reader);
        $contributorsAndLabs = $this->extractContributorsAndMslLaboratories($reader);
        $contributors = $contributorsAndLabs['contributors'];
        $mslLaboratories = $contributorsAndLabs['mslLaboratories'];
        $descriptions = $this->extractDescriptions($reader);
        $dates = $this->extractDates($reader);
        $coverages = $this->extractCoverages($reader, $dates);
        $gcmdKeywords = $this->extractGcmdKeywords($reader);

        // Use dedicated service for keyword extraction
        $keywordExtractor = new XmlKeywordExtractor;
        $freeKeywords = $keywordExtractor->extractFreeKeywords($reader);
        $mslKeywords = $keywordExtractor->extractMslKeywords($reader);

        $rightsElements = $reader
            ->xpathElement('//*[local-name()="rightsList"]/*[local-name()="rights"]')
            ->get();
        $licenses = [];

        foreach ($rightsElements as $element) {
            $identifier = $element->getAttribute('rightsIdentifier');
            if ($identifier) {
                $licenses[] = $identifier;
            }
        }

        $titleElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="titles"]/*[local-name()="title"]')
            ->get();
        $titles = [];

        foreach ($titleElements as $element) {
            $titleType = $element->getAttribute('titleType');
            $titles[] = [
                'title' => $element->getContent(),
                'titleType' => $titleType ? Str::kebab($titleType) : 'main-title',
            ];
        }

        $mainTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] === 'main-title'
        ));
        $otherTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] !== 'main-title'
        ));
        $titles = array_merge($mainTitles, $otherTitles);

        $resourceTypeElement = $this->extractFirstElementFromQuery(
            $reader->xpathElement('//*[local-name()="resourceType"]'),
        );
        $resourceTypeName = $resourceTypeElement?->getAttribute('resourceTypeGeneral');
        $resourceType = null;

        if ($resourceTypeName !== null) {
            $resourceTypeModel = ResourceType::whereRaw('LOWER(name) = ?', [Str::lower($resourceTypeName)])->first();
            $resourceType = $resourceTypeModel?->id;
        }

        $fundingReferences = $this->extractFundingReferences($reader);

        // Store data in session to avoid 414 URI Too Long errors
        $sessionKey = 'xml_upload_'.Str::random(32);

        session()->put($sessionKey, [
            'doi' => $doi,
            'year' => $year,
            'version' => $version,
            'language' => $language,
            'resourceType' => $resourceType !== null ? (string) $resourceType : null,
            'titles' => $titles,
            'licenses' => $licenses,
            'authors' => $authors,
            'contributors' => $contributors,
            'descriptions' => $descriptions,
            'dates' => $dates,
            'coverages' => $coverages,
            'gcmdKeywords' => $gcmdKeywords,
            'freeKeywords' => $freeKeywords,
            'mslKeywords' => $mslKeywords,
            'fundingReferences' => $fundingReferences,
            'mslLaboratories' => $mslLaboratories,
        ]);

        return response()->json([
            'sessionKey' => $sessionKey,
        ]);
    }

    private function extractFirstStringFromQuery(mixed $query): ?string
    {
        if (! is_object($query) || ! method_exists($query, 'first')) {
            return null;
        }

        $value = $query->first();

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function extractFirstElementFromQuery(mixed $query): ?Element
    {
        if (! is_object($query) || ! method_exists($query, 'first')) {
            return null;
        }

        $value = $query->first();

        return $value instanceof Element ? $value : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractAuthors(XmlReader $reader): array
    {
        $creatorElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="creators"]/*[local-name()="creator"]')
            ->get();

        $authors = [];

        foreach ($creatorElements as $creator) {
            $content = $creator->getContent();

            if (! is_array($content)) {
                continue;
            }

            $creatorName = $this->firstElement($content, 'creatorName');
            $nameType = $creatorName?->getAttribute('nameType');
            $type = is_string($nameType) && Str::lower($nameType) === 'organizational' ? 'institution' : 'person';

            $affiliations = $this->extractAffiliations($content);

            if ($type === 'institution') {
                $authors[] = [
                    'type' => 'institution',
                    'institutionName' => $this->stringValue($creatorName) ?? '',
                    'affiliations' => $affiliations,
                ];

                continue;
            }

            $givenName = $this->stringValue($this->firstElement($content, 'givenName'));
            $familyName = $this->stringValue($this->firstElement($content, 'familyName'));

            if ((! $givenName || ! $familyName) && $creatorName instanceof Element) {
                $resolved = $this->splitCreatorName($this->stringValue($creatorName));
                $familyName ??= $resolved['familyName'];
                $givenName ??= $resolved['givenName'];
            }

            $authors[] = [
                'type' => 'person',
                'orcid' => $this->extractOrcid($content),
                'firstName' => $givenName ?? '',
                'lastName' => $familyName ?? ($this->stringValue($creatorName) ?? ''),
                'affiliations' => $affiliations,
            ];
        }

        return $authors;
    }

    /**
     * Extract both regular contributors and MSL laboratories
     *
     * @return array{contributors: array<int, array<string, mixed>>, mslLaboratories: array<int, array<string, string>>}
     */
    private function extractContributorsAndMslLaboratories(XmlReader $reader): array
    {
        $contributorElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="contributors"]/*[local-name()="contributor"]')
            ->get();

        $contributors = [];
        $contributorIndexByKey = [];
        $mslLaboratories = [];

        foreach ($contributorElements as $contributor) {
            $content = $contributor->getContent();

            if (! is_array($content)) {
                continue;
            }

            $contributorType = $contributor->getAttribute('contributorType');
            $roles = $this->extractContributorRoles($contributorType);

            // Check if this is an MSL Laboratory (HostingInstitution with labid)
            // Use contributorType directly for robust detection (case-insensitive)
            $labId = $this->extractLabId($content);
            if ($labId !== null && strcasecmp($contributorType ?? '', 'HostingInstitution') === 0) {
                // This is an MSL Laboratory
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

                continue; // Don't add to contributors
            }

            $nameElement = $this->firstElement($content, 'contributorName');
            $nameType = $nameElement?->getAttribute('nameType');

            $isInstitution = $this->isInstitutionContributor($nameType, $roles);

            if ($isInstitution) {
                $institutionName = $this->stringValue($nameElement) ?? '';
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

            $givenName = $this->stringValue($this->firstElement($content, 'givenName'));
            $familyName = $this->stringValue($this->firstElement($content, 'familyName'));

            if ((! $givenName || ! $familyName) && $nameElement instanceof Element) {
                $resolved = $this->splitCreatorName($this->stringValue($nameElement));
                $familyName ??= $resolved['familyName'];
                $givenName ??= $resolved['givenName'];
            }

            $fallbackLastName = $familyName ?? ($this->stringValue($nameElement) ?? '');

            $contributorData = [
                'type' => 'person',
                'roles' => $roles,
                'orcid' => $this->extractOrcid($content),
                'firstName' => $givenName ?? '',
                'lastName' => $fallbackLastName,
                'affiliations' => $this->extractAffiliations($content),
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
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractDescriptions(XmlReader $reader): array
    {
        $descriptionElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="descriptions"]/*[local-name()="description"]')
            ->get();

        $descriptions = [];

        foreach ($descriptionElements as $element) {
            $descriptionType = $element->getAttribute('descriptionType');
            $description = $element->getContent();

            if (! is_string($description) || trim($description) === '') {
                continue;
            }

            $descriptions[] = [
                'type' => $descriptionType ?? 'Other',
                'description' => $description,
            ];
        }

        return $descriptions;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractDates(XmlReader $reader): array
    {
        $dateElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="dates"]/*[local-name()="date"]')
            ->get();

        $dates = [];

        foreach ($dateElements as $element) {
            $dateType = $element->getAttribute('dateType');
            $dateValue = $element->getContent();

            if (! is_string($dateValue) || trim($dateValue) === '') {
                continue;
            }

            $dateValue = trim($dateValue);

            // Parse date value - can be single date or range (start/end)
            $startDate = '';
            $endDate = '';

            if (str_contains($dateValue, '/')) {
                // Date range format: "2024-01-01/2024-12-31" or open range "/2024-12-31"
                [$start, $end] = explode('/', $dateValue, 2);
                $startDate = trim($start);
                $endDate = trim($end);
            } else {
                // Single date format: "2024-01-01"
                $startDate = $dateValue;
            }

            $dates[] = [
                'dateType' => Str::kebab($dateType ?? 'other'),
                'startDate' => $startDate,
                'endDate' => $endDate,
            ];
        }

        return $dates;
    }

    /**
     * Extract spatial and temporal coverages from DataCite XML.
     *
     * DataCite stores:
     * - Spatial coverage in <geoLocations> (with geoLocationPoint and geoLocationBox)
     * - Temporal coverage as <date dateType="Coverage">
     *
     * @param  array<int, array<string, string>>  $dates  Already extracted dates (to find Coverage date)
     * @return array<int, array<string, string>>
     */
    private function extractCoverages(XmlReader $reader, array $dates): array
    {
        $coverages = [];

        // Extract temporal coverage from dates with type "coverage"
        $temporalCoverage = null;
        foreach ($dates as $date) {
            if (($date['dateType'] ?? '') === 'coverage') {
                $temporalCoverage = $date;
                break;
            }
        }

        // Extract geoLocations
        $geoLocationElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="geoLocations"]/*[local-name()="geoLocation"]')
            ->get();

        if (count($geoLocationElements) === 0 && $temporalCoverage !== null) {
            // Only temporal coverage, no spatial data
            $coverages[] = [
                'id' => 'coverage-1',
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'startDate' => $temporalCoverage['startDate'] ?? '',
                'endDate' => $temporalCoverage['endDate'] ?? '',
                'startTime' => '',
                'endTime' => '',
                'timezone' => 'UTC',
                'description' => '',
            ];

            return $coverages;
        }

        $index = 1;
        foreach ($geoLocationElements as $geoLocationIndex => $geoLocation) {
            $coverage = [
                'id' => 'coverage-'.$index,
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'startDate' => $temporalCoverage['startDate'] ?? '',
                'endDate' => $temporalCoverage['endDate'] ?? '',
                'startTime' => '',
                'endTime' => '',
                'timezone' => 'UTC',
                'description' => '',
            ];

            // Build XPath base for this specific geoLocation element
            // Cast to int to ensure type safety for PHPStan
            $geoLocationPath = '/*[local-name()="resource"]/*[local-name()="geoLocations"]/*[local-name()="geoLocation"]['.((int) $geoLocationIndex + 1).']';

            // Extract geoLocationPlace (description)
            $place = $this->extractFirstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationPlace"]')
            );
            if ($place !== null) {
                $coverage['description'] = trim($place);
            }

            // Extract geoLocationPoint
            $latText = $this->extractFirstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationPoint"]/*[local-name()="pointLatitude"]')
            );
            $lonText = $this->extractFirstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationPoint"]/*[local-name()="pointLongitude"]')
            );

            if ($latText !== null && $lonText !== null) {
                $coverage['latMin'] = $this->formatCoordinate($latText);
                $coverage['lonMin'] = $this->formatCoordinate($lonText);
                // For points, leave latMax and lonMax empty (ERNIE convention)
            }

            // Extract geoLocationBox (takes precedence if both exist)
            $west = $this->extractFirstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="westBoundLongitude"]')
            );
            $east = $this->extractFirstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="eastBoundLongitude"]')
            );
            $south = $this->extractFirstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="southBoundLatitude"]')
            );
            $north = $this->extractFirstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="northBoundLatitude"]')
            );

            if ($west !== null && $east !== null && $south !== null && $north !== null) {
                $coverage['lonMin'] = $this->formatCoordinate($west);
                $coverage['lonMax'] = $this->formatCoordinate($east);
                $coverage['latMin'] = $this->formatCoordinate($south);
                $coverage['latMax'] = $this->formatCoordinate($north);
            }

            // Only add coverage if it has at least coordinates or description
            if ($coverage['latMin'] !== '' || $coverage['lonMin'] !== '' ||
                $coverage['description'] !== '' || $coverage['startDate'] !== '') {
                $coverages[] = $coverage;
                $index++;
            }
        }

        return $coverages;
    }

    /**
     * Format coordinate value to max 6 decimal places.
     */
    private function formatCoordinate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $float = (float) $trimmed;

        return number_format($float, 6, '.', '');
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
        $affiliations = $this->extractAffiliations($content);

        if (! empty($affiliations)) {
            return $affiliations;
        }

        $identifierElements = $this->allElements($content, 'nameIdentifier');

        foreach ($identifierElements as $element) {
            $scheme = $element->getAttribute('nameIdentifierScheme');
            $value = $this->stringValue($element);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $resolved = $this->resolveAffiliationByRor(
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
     * @return array<int, array{value: string, rorId: string|null}>
     */
    private function extractAffiliations(array $content): array
    {
        $affiliationElements = $this->allElements($content, 'affiliation');

        /** @var array<int, array{value: string, rorId: string|null}> $affiliations */
        $affiliations = [];

        foreach ($affiliationElements as $element) {
            $rawValue = $this->stringValue($element);

            $identifier = $element->getAttribute('affiliationIdentifier');
            $scheme = $element->getAttribute('affiliationIdentifierScheme');

            $resolved = null;

            if (is_string($identifier) && $identifier !== '') {
                $resolved = $this->resolveAffiliationByRor($identifier, is_string($scheme) ? $scheme : null, $rawValue);
            }

            if ($resolved !== null) {
                $affiliations[] = $resolved;

                continue;
            }

            if (is_string($rawValue) && $rawValue !== '') {
                $affiliations[] = [
                    'value' => $rawValue,
                    'rorId' => null,
                ];
            }
        }

        return $affiliations;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function extractOrcid(array $content): ?string
    {
        $identifierElements = $this->allElements($content, 'nameIdentifier');

        foreach ($identifierElements as $element) {
            $scheme = $element->getAttribute('nameIdentifierScheme');

            if (is_string($scheme) && Str::lower($scheme) !== 'orcid') {
                continue;
            }

            $value = $this->stringValue($element);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $orcid = $this->canonicaliseOrcid($value);

            if ($orcid !== null) {
                return $orcid;
            }
        }

        return null;
    }

    private function canonicaliseOrcid(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^https?://orcid\.org/#i', $trimmed) === 1) {
            $path = parse_url($trimmed, PHP_URL_PATH);
            $identifier = is_string($path) ? trim($path, '/') : '';
        } else {
            $identifier = trim($trimmed, '/');
        }

        if ($identifier === '') {
            return null;
        }

        // Return only the ORCID identifier (e.g., "0000-0001-5727-2427")
        // without the URL prefix, as the frontend expects this format
        return $identifier;
    }

    /**
     * Extract MSL Laboratory ID (labid) from contributor content
     *
     * @param  array<string, mixed>  $content
     */
    private function extractLabId(array $content): ?string
    {
        $identifierElements = $this->allElements($content, 'nameIdentifier');

        foreach ($identifierElements as $element) {
            $scheme = $element->getAttribute('nameIdentifierScheme');

            if (is_string($scheme) && Str::lower($scheme) === 'labid') {
                $value = $this->stringValue($element);

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * Extract and enrich MSL Laboratory data
     *
     * @param  array<string, mixed>  $content
     * @return array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string}|null
     */
    private function extractMslLaboratory(array $content, string $labId): ?array
    {
        $mslService = app(MslLaboratoryService::class);

        // Get laboratory name from XML
        $nameElement = $this->firstElement($content, 'contributorName');
        $labName = $this->stringValue($nameElement);

        // Get affiliation info from XML
        $affiliationElement = $this->firstElement($content, 'affiliation');
        $affiliationName = $this->stringValue($affiliationElement);

        // Extract ROR if present - check scheme first, then fallback to identifier inspection
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
            // Explicit ROR scheme OR identifier looks like a ROR
            $isRor = ($affiliationScheme === 'ROR') ||
                     str_contains(strtolower($affiliationIdentifier), 'ror.org');

            if ($isRor) {
                // Normalize to full URL if not already
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

        // Enrich with data from Utrecht vocabulary (using labid)
        $enrichedLab = $mslService->enrichLaboratoryData(
            $labId,
            $labName,
            $affiliationName,
            $affiliationRor
        );

        // Validate that we have at least a name
        if (empty($enrichedLab['name'])) {
            Log::warning('MSL Laboratory extracted without name', [
                'labId' => $labId,
            ]);

            return null;
        }

        return $enrichedLab;
    }

    /**
     * @return array{value: string, rorId: string}|null
     */
    private function resolveAffiliationByRor(string $identifier, ?string $scheme, ?string $fallback): ?array
    {
        if (! $this->isRorIdentifier($identifier, $scheme)) {
            return null;
        }

        $canonical = $this->canonicaliseRorId($identifier);

        if ($canonical === null) {
            return null;
        }

        if (! $this->affiliationMapLoaded) {
            $this->loadAffiliationMap();
        }

        $resolved = $this->affiliationMap[$canonical] ?? null;

        if ($resolved !== null) {
            return $resolved;
        }

        $label = is_string($fallback) && $fallback !== '' ? $fallback : $canonical;

        return [
            'value' => $label,
            'rorId' => $canonical,
        ];
    }

    private function isRorIdentifier(string $identifier, ?string $scheme): bool
    {
        // Only treat as ROR if scheme explicitly indicates ROR
        if (is_string($scheme) && Str::lower($scheme) === 'ror') {
            return true;
        }

        // Also accept if identifier contains ror.org (URL format)
        return Str::contains(Str::lower($identifier), 'ror.org');
    }

    private function canonicaliseRorId(string $identifier): ?string
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return null;
        }

        // If already a full URL, extract and normalize
        if (preg_match('#^https?://ror\.org/(.+)$#i', $trimmed, $matches)) {
            $rorId = trim($matches[1]);

            return $rorId !== '' ? 'https://ror.org/'.Str::lower($rorId) : null;
        }

        // If it contains a path structure, extract it
        $parsed = parse_url($trimmed);
        if ($parsed !== false && isset($parsed['path'])) {
            $path = trim((string) $parsed['path'], '/');

            return $path !== '' ? 'https://ror.org/'.Str::lower($path) : null;
        }

        // Otherwise, treat as ROR ID and prepend URL
        $path = Str::lower(trim($trimmed, '/'));

        return $path !== '' ? 'https://ror.org/'.$path : null;
    }

    private function loadAffiliationMap(): void
    {
        $this->affiliationMapLoaded = true;

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists('ror/ror-affiliations.json')) {
                return;
            }

            $contents = $disk->get('ror/ror-affiliations.json');

            if (! is_string($contents)) {
                return;
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                return;
            }

            foreach ($decoded as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $label = isset($entry['prefLabel']) && is_string($entry['prefLabel'])
                    ? trim($entry['prefLabel'])
                    : '';
                $rorId = isset($entry['rorId']) && is_string($entry['rorId']) ? $this->canonicaliseRorId($entry['rorId']) : null;

                if ($label === '' || $rorId === null) {
                    continue;
                }

                $this->affiliationMap[$rorId] = [
                    'value' => $label,
                    'rorId' => $rorId,
                ];
            }
        } catch (JsonException) {
            // Ignore invalid cache contents and fall back to raw affiliation labels.
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function firstElement(array $content, string $key): ?Element
    {
        $elements = $this->allElements($content, $key);

        return $elements[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return Element[]
     */
    private function allElements(array $content, string $key): array
    {
        if (! array_key_exists($key, $content)) {
            return [];
        }

        return $this->normaliseToElementList($content[$key]);
    }

    /**
     * @return Element[]
     */
    private function normaliseToElementList(mixed $value): array
    {
        if ($value instanceof Element) {
            $content = $value->getContent();

            if (is_array($content)) {
                $elements = [];

                foreach ($content as $nested) {
                    array_push($elements, ...$this->normaliseToElementList($nested));
                }

                return $elements ?: [$value];
            }

            return [$value];
        }

        if (is_array($value)) {
            $elements = [];

            foreach ($value as $nested) {
                array_push($elements, ...$this->normaliseToElementList($nested));
            }

            return $elements;
        }

        return [];
    }

    private function stringValue(?Element $element): ?string
    {
        if (! $element instanceof Element) {
            return null;
        }

        $content = $element->getContent();

        if (is_string($content)) {
            $trimmed = trim($content);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $value) {
                $text = $this->stringValue($value instanceof Element ? $value : null);

                if ($text !== null) {
                    $parts[] = $text;
                }
            }

            if (! empty($parts)) {
                return trim(implode(' ', $parts));
            }
        }

        return null;
    }

    /**
     * @return array{givenName: string|null, familyName: string|null}
     */
    private function splitCreatorName(?string $name): array
    {
        if (! is_string($name) || $name === '') {
            return ['givenName' => null, 'familyName' => null];
        }

        $parts = array_map('trim', explode(',', $name, 2));

        if (count($parts) === 2) {
            return [
                'familyName' => $parts[0] !== '' ? $parts[0] : null,
                'givenName' => $parts[1] !== '' ? $parts[1] : null,
            ];
        }

        return [
            'familyName' => $name,
            'givenName' => null,
        ];
    }

    /**
     * Extract GCMD keywords from the XML.
     *
     * @return array<int, array{uuid: string, id: string, path: string[], scheme: string}>
     */
    private function extractGcmdKeywords(XmlReader $reader): array
    {
        $subjectElements = $reader
            ->xpathElement('//*[local-name()="subjects"]/*[local-name()="subject"]')
            ->get();

        $keywords = [];

        foreach ($subjectElements as $element) {
            $scheme = $element->getAttribute('subjectScheme');
            $valueUri = $element->getAttribute('valueURI');
            $content = $this->stringValue($element);

            if (! $scheme || ! $valueUri || ! $content) {
                continue;
            }

            // Only process GCMD keywords (Science Keywords, Platforms, Instruments)
            $isGcmdKeyword = stripos($scheme, 'Science Keywords') !== false ||
                            stripos($scheme, 'Platforms') !== false ||
                            stripos($scheme, 'Instruments') !== false;

            if (! $isGcmdKeyword) {
                continue;
            }

            // Extract UUID from valueURI using shared utility
            $uuid = GcmdUriHelper::extractUuid($valueUri);

            if (! $uuid) {
                continue;
            }

            // Build full ID URL using shared utility
            $id = GcmdUriHelper::buildConceptUri($uuid);

            // Parse the hierarchical path using shared utility
            // Example: "Science Keywords > EARTH SCIENCE > ATMOSPHERE" -> ["EARTH SCIENCE", "ATMOSPHERE"]
            $path = XmlKeywordExtractor::parseGcmdPath($content);

            // Normalize scheme name (e.g., "NASA/GCMD Earth Science Keywords" -> "Science Keywords")
            $normalizedScheme = $scheme;
            if (stripos($scheme, 'Science') !== false) {
                $normalizedScheme = 'Science Keywords';
            } elseif (stripos($scheme, 'Platform') !== false) {
                $normalizedScheme = 'Platforms';
            } elseif (stripos($scheme, 'Instrument') !== false) {
                $normalizedScheme = 'Instruments';
            }

            $keywords[] = [
                'uuid' => $uuid,
                'id' => $id,
                'path' => $path,
                'scheme' => $normalizedScheme,
            ];
        }

        return $keywords;
    }

    /**
     * Extract funding references from DataCite XML.
     *
     * @return array<int, array{funderName: string, funderIdentifier: string|null, funderIdentifierType: string|null, awardNumber: string|null, awardUri: string|null, awardTitle: string|null}>
     */
    private function extractFundingReferences(XmlReader $reader): array
    {
        $fundingElements = $reader
            ->xpathElement('//*[local-name()="fundingReferences"]/*[local-name()="fundingReference"]')
            ->get();

        $fundingReferences = [];

        foreach ($fundingElements as $fundingElement) {
            $content = $fundingElement->getContent();

            if (! is_array($content)) {
                continue;
            }

            // Extract funderName (required in DataCite schema)
            $funderNameElement = $this->firstElement($content, 'funderName');
            $funderName = $this->stringValue($funderNameElement);

            if (! $funderName) {
                // Skip if funderName is missing (required field)
                continue;
            }

            // Extract funderIdentifier and its type
            $funderIdentifierElement = $this->firstElement($content, 'funderIdentifier');
            $funderIdentifier = $this->stringValue($funderIdentifierElement);
            $funderIdentifierType = $funderIdentifierElement?->getAttribute('funderIdentifierType');

            // Extract awardNumber and awardURI
            $awardNumberElement = $this->firstElement($content, 'awardNumber');
            $awardNumber = $this->stringValue($awardNumberElement);
            $awardUri = $awardNumberElement?->getAttribute('awardURI');

            // Extract awardTitle
            $awardTitleElement = $this->firstElement($content, 'awardTitle');
            $awardTitle = $this->stringValue($awardTitleElement);

            $fundingReferences[] = [
                'funderName' => $funderName,
                'funderIdentifier' => $funderIdentifier,
                'funderIdentifierType' => $funderIdentifierType,
                'awardNumber' => $awardNumber,
                'awardUri' => $awardUri,
                'awardTitle' => $awardTitle,
            ];
        }

        return $fundingReferences;
    }
}
