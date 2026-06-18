<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RelatedIdentifier;
use App\Models\TitleType;
use App\Services\DoiSuggestionService;
use App\Support\BooleanNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Relaxed validation for saving draft resources (Issue #548).
 *
 * Only requires a Main Title. All other fields are optional. Complete related
 * entries are structurally validated when provided, while incomplete draft-only
 * author/contributor rows are ignored until they can be saved safely.
 */
class StoreDraftResourceRequest extends FormRequest
{
    /**
     * Set of valid DB title type slugs for quick in-request validation.
     *
     * @var array<string, true>
     */
    private array $titleTypeDbSlugSet = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resourceId' => ['nullable', 'integer', Rule::exists('resources', 'id')],
            'doi' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('resources', 'doi')
                    ->ignore($this->input('resourceId')),
            ],
            // Year is optional for drafts
            'year' => ['nullable', 'integer', 'between:1000,9999'],
            // Resource type is optional for drafts
            'resourceType' => ['nullable', 'integer', Rule::exists('resource_types', 'id')],
            'version' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', Rule::exists('languages', 'code')],
            // Title is required (at least one)
            'titles' => ['required', 'array', 'min:1'],
            'titles.*.title' => ['required', 'string', 'max:255'],
            'titles.*.titleType' => ['required', 'string', 'max:255'],
            'titles.*.language' => ['nullable', 'string', 'max:10'],
            // Licenses are optional for drafts
            'licenses' => ['nullable', 'array'],
            'licenses.*' => ['string', 'distinct', Rule::exists('rights', 'identifier')],
            'rawRights' => ['nullable', 'array'],
            'rawRights.*.rights' => ['nullable', 'string'],
            'rawRights.*.rightsUri' => ['nullable', 'string', 'max:512'],
            'rawRights.*.rightsIdentifier' => ['nullable', 'string', 'max:255'],
            'rawRights.*.rightsIdentifierScheme' => ['nullable', 'string', 'max:100'],
            'rawRights.*.schemeUri' => ['nullable', 'string', 'max:512'],
            'rawRights.*.lang' => ['nullable', 'string', 'max:10'],
            'rawRights.*.source' => ['nullable', 'string', 'max:100'],
            // Authors are optional for drafts
            'authors' => ['nullable', 'array'],
            'authors.*.type' => ['required', Rule::in(['person', 'institution'])],
            'authors.*.position' => ['required', 'integer', 'min:0'],
            'authors.*.orcid' => ['nullable', 'string', 'max:255'],
            'authors.*.firstName' => ['nullable', 'string', 'max:255'],
            'authors.*.lastName' => ['nullable', 'string', 'max:255'],
            'authors.*.email' => ['nullable', 'email', 'max:255'],
            'authors.*.website' => ['nullable', 'url', 'max:255'],
            'authors.*.isContact' => ['boolean'],
            'authors.*.institutionName' => ['nullable', 'string', 'max:255'],
            'authors.*.rorId' => ['nullable', 'string', 'max:255'],
            'authors.*.affiliations' => ['nullable', 'array'],
            'authors.*.affiliations.*.value' => ['nullable', 'string', 'max:255'],
            'authors.*.affiliations.*.rorId' => ['nullable', 'string', 'max:255'],
            'contributors' => ['nullable', 'array'],
            'contributors.*.type' => ['required', Rule::in(['person', 'institution'])],
            'contributors.*.position' => ['required', 'integer', 'min:0'],
            'contributors.*.roles' => ['nullable', 'array'],
            'contributors.*.roles.*' => ['required', 'string', 'max:255'],
            'contributors.*.orcid' => ['nullable', 'string', 'max:255'],
            'contributors.*.firstName' => ['nullable', 'string', 'max:255'],
            'contributors.*.lastName' => ['nullable', 'string', 'max:255'],
            'contributors.*.institutionName' => ['nullable', 'string', 'max:255'],
            'contributors.*.identifier' => ['nullable', 'string', 'max:255'],
            'contributors.*.identifierType' => ['nullable', 'string', 'max:50'],
            'contributors.*.affiliations' => ['nullable', 'array'],
            'contributors.*.affiliations.*.value' => ['nullable', 'string', 'max:255'],
            'contributors.*.affiliations.*.rorId' => ['nullable', 'string', 'max:255'],
            'contributors.*.email' => ['nullable', 'string', 'email', 'max:255'],
            'contributors.*.website' => ['nullable', 'string', 'url:http,https', 'max:255'],
            // Descriptions are optional for drafts
            'descriptions' => ['nullable', 'array'],
            'descriptions.*.descriptionType' => [
                'required',
                'string',
                Rule::in(['abstract', 'methods', 'series-information', 'table-of-contents', 'technical-info', 'other']),
            ],
            'descriptions.*.description' => ['required', 'string'],
            'descriptions.*.language' => ['nullable', 'string', 'max:10'],
            'dates' => ['nullable', 'array'],
            'dates.*.dateType' => [
                'required',
                'string',
                Rule::in(['accepted', 'available', 'collected', 'copyrighted', 'created', 'issued', 'submitted', 'updated', 'valid', 'withdrawn', 'other']),
            ],
            'dates.*.startDate' => ['nullable', 'date'],
            'dates.*.endDate' => ['nullable', 'date'],
            'dates.*.dateInformation' => ['nullable', 'string', 'max:255'],
            'importedCreatedDate' => ['nullable', 'date'],
            'freeKeywords' => ['nullable', 'array'],
            'freeKeywords.*' => ['string', 'max:255'],
            'gcmdKeywords' => ['nullable', 'array'],
            'gcmdKeywords.*.id' => ['required', 'string', 'max:512'],
            'gcmdKeywords.*.text' => ['required', 'string', 'max:255'],
            'gcmdKeywords.*.path' => ['required', 'string'],
            'gcmdKeywords.*.language' => ['nullable', 'string', 'max:10'],
            'gcmdKeywords.*.scheme' => ['required', 'string', 'max:255'],
            'gcmdKeywords.*.schemeURI' => ['nullable', 'string', 'max:512'],
            'gcmdKeywords.*.classificationCode' => ['nullable', 'string', 'max:255'],
            'spatialTemporalCoverages' => ['nullable', 'array'],
            'spatialTemporalCoverages.*.type' => ['required', Rule::in(['point', 'box', 'polygon', 'line'])],
            'spatialTemporalCoverages.*.latMin' => ['nullable', 'numeric', 'between:-90,90'],
            'spatialTemporalCoverages.*.latMax' => ['nullable', 'numeric', 'between:-90,90'],
            'spatialTemporalCoverages.*.lonMin' => ['nullable', 'numeric', 'between:-180,180'],
            'spatialTemporalCoverages.*.lonMax' => ['nullable', 'numeric', 'between:-180,180'],
            'spatialTemporalCoverages.*.polygonPoints' => ['nullable', 'array'],
            'spatialTemporalCoverages.*.polygonPoints.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'spatialTemporalCoverages.*.polygonPoints.*.lon' => ['required', 'numeric', 'between:-180,180'],
            'spatialTemporalCoverages.*.startDate' => ['nullable', 'date'],
            'spatialTemporalCoverages.*.endDate' => ['nullable', 'date'],
            'spatialTemporalCoverages.*.startTime' => ['nullable', 'date_format:H:i:s,H:i'],
            'spatialTemporalCoverages.*.endTime' => ['nullable', 'date_format:H:i:s,H:i'],
            'spatialTemporalCoverages.*.timezone' => ['nullable', 'string', 'max:100'],
            'spatialTemporalCoverages.*.description' => ['nullable', 'string'],
            'relatedIdentifiers' => ['nullable', 'array'],
            'relatedIdentifiers.*.identifier' => ['required', 'string', 'max:2183'],
            'relatedIdentifiers.*.identifierType' => [
                'required',
                'string',
                Rule::in(['DOI', 'URL', 'Handle', 'IGSN', 'URN', 'ISBN', 'ISSN', 'PURL', 'ARK', 'arXiv', 'bibcode', 'CSTR', 'EAN13', 'EISSN', 'ISTC', 'LISSN', 'LSID', 'PMID', 'RAiD', 'RRID', 'SWHID', 'UPC', 'w3id']),
            ],
            'relatedIdentifiers.*.relationType' => [
                'required',
                'string',
                Rule::in([
                    'Cites', 'IsCitedBy', 'References', 'IsReferencedBy',
                    'IsSupplementTo', 'IsSupplementedBy', 'IsContinuedBy', 'Continues',
                    'Describes', 'IsDescribedBy', 'HasMetadata', 'IsMetadataFor',
                    'HasVersion', 'IsVersionOf', 'IsNewVersionOf', 'IsPreviousVersionOf',
                    'IsPartOf', 'HasPart', 'IsPublishedIn',
                    'IsDocumentedBy', 'Documents', 'IsCompiledBy', 'Compiles',
                    'IsVariantFormOf', 'IsOriginalFormOf', 'IsIdenticalTo',
                    'IsReviewedBy', 'Reviews', 'IsDerivedFrom', 'IsSourceOf',
                    'IsRequiredBy', 'Requires', 'IsCollectedBy', 'Collects',
                    'IsObsoletedBy', 'Obsoletes',
                    'HasTranslation', 'IsTranslationOf', 'Other',
                ]),
            ],
            'relatedIdentifiers.*.relationTypeInformation' => ['nullable', 'string', 'max:255'],
            'relatedIdentifiers.*.citationLabel' => ['nullable', 'string', 'max:'.RelatedIdentifier::MAX_CITATION_LABEL_CHARACTERS],
            'fundingReferences' => ['nullable', 'array', 'max:99'],
            'fundingReferences.*.funderName' => ['required', 'string', 'max:500'],
            'fundingReferences.*.funderIdentifier' => ['nullable', 'string', 'max:500'],
            'fundingReferences.*.funderIdentifierType' => ['nullable', 'string', 'in:ROR,Crossref Funder ID,ISNI,GRID,Other'],
            'fundingReferences.*.awardNumber' => ['nullable', 'string', 'max:255'],
            'fundingReferences.*.awardUri' => ['nullable', 'url', 'max:2048'],
            'fundingReferences.*.awardTitle' => ['nullable', 'string', 'max:500'],
            'mslLaboratories' => ['nullable', 'array'],
            'mslLaboratories.*.identifier' => ['required', 'string', 'max:255'],
            'mslLaboratories.*.name' => ['required', 'string', 'max:255'],
            'mslLaboratories.*.affiliation_name' => ['nullable', 'string', 'max:255'],
            'mslLaboratories.*.affiliation_ror' => ['nullable', 'string', 'max:255'],
            'mslLaboratories.*.position' => ['required', 'integer', 'min:0'],
            'instruments' => ['nullable', 'array', 'max:100'],
            'instruments.*.pid' => ['required', 'string', 'max:512'],
            'instruments.*.pidType' => ['required', 'string', Rule::in(['Handle', 'DOI', 'URL'])],
            'instruments.*.name' => ['required', 'string', 'max:1024'],
            // Datacenters are optional for drafts
            'datacenters' => ['nullable', 'array'],
            'datacenters.*' => ['integer', 'distinct', Rule::exists('datacenters', 'id')],
        ];
    }

    /**
     * Input normalization – reuses the same logic as StoreResourceRequest.
     */
    protected function prepareForValidation(): void
    {
        /** @var array<int, array<string, mixed>|mixed> $rawTitles */
        $rawTitles = $this->input('titles', []);

        /** @var array<string, string> $titleTypeSlugLookup */
        $titleTypeSlugLookup = [];

        /** @var array<string, true> $titleTypeDbSlugSet */
        $titleTypeDbSlugSet = [];

        $titleTypeLookupLoaded = false;

        $titles = [];

        foreach ($rawTitles as $title) {
            if (! is_array($title)) {
                $title = [];
            }

            $titleType = isset($title['titleType']) ? trim((string) $title['titleType']) : null;
            if ($titleType !== null && $titleType !== '') {
                $normalized = Str::kebab($titleType);

                if ($normalized === 'main-title') {
                    $titleType = 'main-title';
                } elseif ($normalized !== '') {
                    if (! $titleTypeLookupLoaded) {
                        /** @var array<int, string> $dbSlugs */
                        $dbSlugs = TitleType::query()->pluck('slug')->all();

                        foreach ($dbSlugs as $slug) {
                            $titleTypeDbSlugSet[$slug] = true;
                            $titleTypeSlugLookup[Str::kebab($slug)] = $slug;
                        }

                        $titleTypeLookupLoaded = true;
                    }

                    if (isset($titleTypeSlugLookup[$normalized])) {
                        $titleType = $titleTypeSlugLookup[$normalized];
                    }
                }
            }

            $language = isset($title['language']) ? trim((string) $title['language']) : '';

            $titles[] = [
                'title' => isset($title['title']) ? trim((string) $title['title']) : null,
                'titleType' => $titleType,
                'language' => $language !== '' ? $language : null,
            ];
        }

        /** @var mixed $rawLicensesInput */
        $rawLicensesInput = $this->input('licenses', []);
        $rawLicenses = is_array($rawLicensesInput) ? $rawLicensesInput : [];

        $licenses = [];

        foreach ($rawLicenses as $license) {
            $normalized = trim((string) $license);

            if ($normalized === '' || in_array($normalized, $licenses, true)) {
                continue;
            }

            $licenses[] = $normalized;
        }

        $normalizedLicenses = is_array($rawLicensesInput) || $rawLicensesInput === null
            ? $licenses
            : $rawLicensesInput;

        /** @var mixed $rawRightsInput */
        $rawRightsInput = $this->input('rawRights', []);
        $rawRights = $this->normalizeRawRightsInput($rawRightsInput);

        /** @var array<int, array<string, mixed>|mixed> $rawAuthors */
        $rawAuthors = $this->input('authors', []);

        $authors = [];

        foreach ($rawAuthors as $index => $author) {
            if (! is_array($author)) {
                continue;
            }

            $typeCandidate = isset($author['type']) ? trim((string) $author['type']) : '';
            $type = $typeCandidate !== '' ? $typeCandidate : 'person';

            $affiliations = [];
            $seenAffiliations = [];

            $rawAffiliations = $author['affiliations'] ?? [];

            if (is_array($rawAffiliations)) {
                foreach ($rawAffiliations as $affiliation) {
                    if (is_string($affiliation)) {
                        $value = trim($affiliation);

                        if ($value === '') {
                            continue;
                        }

                        $key = $value.'|';

                        if (isset($seenAffiliations[$key])) {
                            continue;
                        }

                        $seenAffiliations[$key] = true;
                        $affiliations[] = [
                            'value' => $value,
                            'rorId' => null,
                        ];

                        continue;
                    }

                    if (! is_array($affiliation)) {
                        continue;
                    }

                    $value = isset($affiliation['value']) ? trim((string) $affiliation['value']) : '';
                    $rorId = isset($affiliation['rorId']) ? trim((string) $affiliation['rorId']) : '';

                    if ($value === '' && $rorId === '') {
                        continue;
                    }

                    $normalizedValue = $value !== '' ? $value : $rorId;
                    $normalizedRorId = $rorId !== '' ? $rorId : null;

                    $key = $normalizedValue.'|'.($normalizedRorId ?? '');

                    if (isset($seenAffiliations[$key])) {
                        continue;
                    }

                    $seenAffiliations[$key] = true;

                    $affiliations[] = [
                        'value' => $normalizedValue,
                        'rorId' => $normalizedRorId,
                    ];
                }
            }

            if (! in_array($type, ['person', 'institution'], true)) {
                $authors[$index] = [
                    'type' => $type,
                    'affiliations' => $affiliations,
                    'position' => (int) $index,
                ];

                continue;
            }

            if ($type === 'institution') {
                $authors[$index] = [
                    'type' => 'institution',
                    'institutionName' => $this->normalizeString($author['institutionName'] ?? null),
                    'rorId' => $this->normalizeString($author['rorId'] ?? null),
                    'affiliations' => $affiliations,
                    'position' => (int) $index,
                ];

                continue;
            }

            $isContact = BooleanNormalizer::isTrue($author['isContact'] ?? false);

            $email = $this->normalizeString($author['email'] ?? null);
            $website = $this->normalizeString($author['website'] ?? null);

            if (! $isContact) {
                $email = null;
                $website = null;
            }

            $authors[$index] = [
                'type' => 'person',
                'orcid' => $this->normalizeString($author['orcid'] ?? null),
                'firstName' => $this->normalizeString($author['firstName'] ?? null),
                'lastName' => $this->normalizeString($author['lastName'] ?? null),
                'email' => $email,
                'website' => $website,
                'isContact' => $isContact,
                'affiliations' => $affiliations,
                'position' => (int) $index,
            ];
        }

        $authors = array_filter(
            $authors,
            fn (array $author): bool => $this->shouldKeepDraftAuthor($author),
        );

        /** @var array<int, array<string, mixed>|mixed> $rawContributors */
        $rawContributors = $this->input('contributors', []);

        $contributors = [];

        foreach ($rawContributors as $index => $contributor) {
            if (! is_array($contributor)) {
                continue;
            }

            $typeCandidate = isset($contributor['type']) ? trim((string) $contributor['type']) : '';
            $type = $typeCandidate !== '' ? $typeCandidate : 'person';

            $affiliations = [];
            $seenAffiliations = [];

            $rawAffiliations = $contributor['affiliations'] ?? [];

            if (is_array($rawAffiliations)) {
                foreach ($rawAffiliations as $affiliation) {
                    if (is_string($affiliation)) {
                        $value = trim($affiliation);

                        if ($value === '') {
                            continue;
                        }

                        $key = $value.'|';

                        if (isset($seenAffiliations[$key])) {
                            continue;
                        }

                        $seenAffiliations[$key] = true;
                        $affiliations[] = [
                            'value' => $value,
                            'rorId' => null,
                        ];

                        continue;
                    }

                    if (! is_array($affiliation)) {
                        continue;
                    }

                    $value = isset($affiliation['value']) ? trim((string) $affiliation['value']) : '';
                    $rorId = isset($affiliation['rorId']) ? trim((string) $affiliation['rorId']) : '';

                    if ($value === '' && $rorId === '') {
                        continue;
                    }

                    $normalizedValue = $value !== '' ? $value : $rorId;
                    $normalizedRorId = $rorId !== '' ? $rorId : null;

                    $key = $normalizedValue.'|'.($normalizedRorId ?? '');

                    if (isset($seenAffiliations[$key])) {
                        continue;
                    }

                    $seenAffiliations[$key] = true;

                    $affiliations[] = [
                        'value' => $normalizedValue,
                        'rorId' => $normalizedRorId,
                    ];
                }
            }

            // Normalize roles
            $roles = null;
            $rawRoles = $contributor['roles'] ?? null;

            if (is_array($rawRoles)) {
                $roles = [];

                foreach ($rawRoles as $roleIndex => $role) {
                    if (! is_string($role)) {
                        $roles[$roleIndex] = $role;

                        continue;
                    }

                    $normalizedRole = trim($role);
                    if ($normalizedRole !== '') {
                        $roles[$roleIndex] = $normalizedRole;
                    }
                }
            } elseif (array_key_exists('roles', $contributor)) {
                $roles = $rawRoles;
            }

            if (! in_array($type, ['person', 'institution'], true)) {
                $contributors[$index] = [
                    'type' => $type,
                    'roles' => $roles,
                    'affiliations' => $affiliations,
                    'position' => (int) $index,
                ];

                continue;
            }

            if ($type === 'institution') {
                $contributors[$index] = [
                    'type' => 'institution',
                    'institutionName' => $this->normalizeString($contributor['institutionName'] ?? null),
                    'identifier' => $this->normalizeString($contributor['identifier'] ?? null),
                    'identifierType' => $this->normalizeString($contributor['identifierType'] ?? null),
                    'roles' => $roles,
                    'affiliations' => $affiliations,
                    'position' => (int) $index,
                ];

                continue;
            }

            $contributors[$index] = [
                'type' => 'person',
                'orcid' => $this->normalizeString($contributor['orcid'] ?? null),
                'firstName' => $this->normalizeString($contributor['firstName'] ?? null),
                'lastName' => $this->normalizeString($contributor['lastName'] ?? null),
                'email' => $this->normalizeString($contributor['email'] ?? null),
                'website' => $this->normalizeString($contributor['website'] ?? null),
                'roles' => $roles,
                'affiliations' => $affiliations,
                'position' => (int) $index,
            ];
        }

        $contributors = array_filter(
            $contributors,
            fn (array $contributor): bool => $this->shouldKeepDraftContributor($contributor),
        );

        // Normalize descriptions
        /** @var array<int, array<string, mixed>|mixed> $rawDescriptions */
        $rawDescriptions = $this->input('descriptions', []);

        $descriptions = [];

        foreach ($rawDescriptions as $description) {
            if (! is_array($description)) {
                continue;
            }

            $descriptionType = isset($description['descriptionType'])
                ? trim((string) $description['descriptionType'])
                : '';
            $descriptionText = isset($description['description'])
                ? trim((string) $description['description'])
                : '';

            if ($descriptionType === '' || $descriptionText === '') {
                continue;
            }

            $normalizedType = Str::kebab($descriptionType);

            $descriptionLanguage = isset($description['language']) ? trim((string) $description['language']) : '';

            $descriptions[] = [
                'descriptionType' => $normalizedType,
                'description' => $descriptionText,
                'language' => $descriptionLanguage !== '' ? $descriptionLanguage : null,
            ];
        }

        // Normalize dates
        /** @var array<int, array<string, mixed>|mixed> $rawDates */
        $rawDates = $this->input('dates', []);

        $dates = [];

        foreach ($rawDates as $date) {
            if (! is_array($date)) {
                continue;
            }

            $dateType = isset($date['dateType'])
                ? trim((string) $date['dateType'])
                : '';
            $startDate = isset($date['startDate'])
                ? trim((string) $date['startDate'])
                : null;
            $endDate = isset($date['endDate'])
                ? trim((string) $date['endDate'])
                : null;
            $dateInformation = isset($date['dateInformation'])
                ? trim((string) $date['dateInformation'])
                : null;

            if ($dateType === '') {
                continue;
            }

            $normalizedType = Str::kebab($dateType);

            $dates[] = [
                'dateType' => $normalizedType,
                'startDate' => $startDate !== '' ? $startDate : null,
                'endDate' => $endDate !== '' ? $endDate : null,
                'dateInformation' => $dateInformation !== '' ? $dateInformation : null,
            ];
        }

        // Process MSL Laboratories
        /** @var array<int, array<string, mixed>|mixed> $rawMslLaboratories */
        $rawMslLaboratories = $this->input('mslLaboratories', []);

        $mslLaboratories = [];

        foreach ($rawMslLaboratories as $index => $lab) {
            if (! is_array($lab)) {
                continue;
            }

            $identifier = isset($lab['identifier']) ? trim((string) $lab['identifier']) : '';
            $name = isset($lab['name']) ? trim((string) $lab['name']) : '';
            $affiliationName = isset($lab['affiliation_name']) ? trim((string) $lab['affiliation_name']) : '';
            $affiliationRor = isset($lab['affiliation_ror']) ? trim((string) $lab['affiliation_ror']) : '';

            if ($identifier === '' || $name === '') {
                continue;
            }

            $mslLaboratories[] = [
                'identifier' => $identifier,
                'name' => $name,
                'affiliation_name' => $affiliationName !== '' ? $affiliationName : null,
                'affiliation_ror' => $affiliationRor !== '' ? $affiliationRor : null,
                'position' => (int) $index,
            ];
        }

        // Normalize spatial temporal coverages
        /** @var array<int, array<string, mixed>|mixed> $rawCoverages */
        $rawCoverages = $this->input('spatialTemporalCoverages', []);

        $coverages = [];

        foreach ($rawCoverages as $coverage) {
            if (! is_array($coverage)) {
                continue;
            }

            $type = isset($coverage['type']) ? trim((string) $coverage['type']) : 'point';
            if (! in_array($type, ['point', 'box', 'polygon', 'line'], true)) {
                $type = 'point';
            }

            $polygonPoints = null;
            if (in_array($type, ['polygon', 'line'], true) && isset($coverage['polygonPoints']) && is_array($coverage['polygonPoints'])) {
                $polygonPoints = [];
                foreach ($coverage['polygonPoints'] as $point) {
                    if (! is_array($point)) {
                        continue;
                    }

                    $lat = $point['lat'] ?? null;
                    $lon = $point['lon'] ?? null;

                    if ($lat !== null && $lon !== null) {
                        $polygonPoints[] = [
                            'lat' => is_numeric($lat) ? (float) $lat : null,
                            'lon' => is_numeric($lon) ? (float) $lon : null,
                        ];
                    }
                }
            }

            $coverages[] = [
                'type' => $type,
                'placeNames' => isset($coverage['placeNames']) && is_array($coverage['placeNames'])
                    ? array_values(array_filter(array_map('trim', $coverage['placeNames'])))
                    : [],
                'geoLocationBox' => $coverage['geoLocationBox'] ?? null,
                'latMin' => $coverage['latMin'] ?? null,
                'latMax' => $coverage['latMax'] ?? null,
                'lonMin' => $coverage['lonMin'] ?? null,
                'lonMax' => $coverage['lonMax'] ?? null,
                'polygonPoints' => $polygonPoints,
                'startDate' => isset($coverage['startDate']) ? trim((string) $coverage['startDate']) : null,
                'endDate' => isset($coverage['endDate']) ? trim((string) $coverage['endDate']) : null,
                'startTime' => isset($coverage['startTime']) ? trim((string) $coverage['startTime']) : null,
                'endTime' => isset($coverage['endTime']) ? trim((string) $coverage['endTime']) : null,
                'timezone' => isset($coverage['timezone']) ? trim((string) $coverage['timezone']) : null,
                'description' => isset($coverage['description']) ? trim((string) $coverage['description']) : null,
            ];
        }

        // Normalize related identifiers
        /** @var array<int, array<string, mixed>|mixed> $rawRelatedIdentifiers */
        $rawRelatedIdentifiers = $this->input('relatedIdentifiers', []);

        $relatedIdentifiers = [];

        foreach ($rawRelatedIdentifiers as $relatedIdentifier) {
            if (! is_array($relatedIdentifier)) {
                continue;
            }

            $identifier = isset($relatedIdentifier['identifier'])
                ? trim((string) $relatedIdentifier['identifier'])
                : '';

            $identifierType = isset($relatedIdentifier['identifierType'])
                ? trim((string) $relatedIdentifier['identifierType'])
                : '';

            $relationType = isset($relatedIdentifier['relationType'])
                ? trim((string) $relatedIdentifier['relationType'])
                : '';

            if ($identifier === '') {
                continue;
            }

            $relationTypeInformation = isset($relatedIdentifier['relationTypeInformation'])
                ? trim((string) $relatedIdentifier['relationTypeInformation'])
                : '';

            $citationLabel = isset($relatedIdentifier['citationLabel'])
                ? trim((string) $relatedIdentifier['citationLabel'])
                : '';

            $relatedIdentifiers[] = [
                'identifier' => $identifier,
                'identifierType' => $identifierType,
                'relationType' => $relationType,
                ...($relationTypeInformation !== '' ? ['relationTypeInformation' => $relationTypeInformation] : []),
                ...($citationLabel !== '' ? ['citationLabel' => $citationLabel] : []),
            ];
        }

        // Normalize funding references
        /** @var array<int, array<string, mixed>|mixed> $rawFundingReferences */
        $rawFundingReferences = $this->input('fundingReferences', []);

        $fundingReferences = [];

        foreach ($rawFundingReferences as $funding) {
            if (! is_array($funding)) {
                continue;
            }

            $funderName = isset($funding['funderName'])
                ? trim((string) $funding['funderName'])
                : '';

            if ($funderName === '') {
                continue;
            }

            $fundingReferences[] = [
                'funderName' => $funderName,
                'funderIdentifier' => isset($funding['funderIdentifier'])
                    ? trim((string) $funding['funderIdentifier'])
                    : null,
                'funderIdentifierType' => isset($funding['funderIdentifierType'])
                    ? trim((string) $funding['funderIdentifierType'])
                    : null,
                'awardNumber' => isset($funding['awardNumber'])
                    ? trim((string) $funding['awardNumber'])
                    : null,
                'awardUri' => isset($funding['awardUri'])
                    ? trim((string) $funding['awardUri'])
                    : null,
                'awardTitle' => isset($funding['awardTitle'])
                    ? trim((string) $funding['awardTitle'])
                    : null,
            ];
        }

        $this->merge([
            'doi' => $this->normalizeDoiInput($this->input('doi')),
            'year' => $this->filled('year') ? (int) $this->input('year') : null,
            'resourceType' => $this->filled('resourceType') ? (int) $this->input('resourceType') : null,
            'version' => $this->filled('version') ? trim((string) $this->input('version')) : null,
            'language' => $this->filled('language') ? trim((string) $this->input('language')) : null,
            'titles' => $titles,
            'licenses' => $normalizedLicenses,
            'rawRights' => $rawRights,
            'resourceId' => $this->filled('resourceId') ? (int) $this->input('resourceId') : null,
            'authors' => $authors,
            'contributors' => $contributors,
            'mslLaboratories' => $mslLaboratories,
            'descriptions' => $descriptions,
            'dates' => $dates,
            'spatialTemporalCoverages' => $coverages,
            'relatedIdentifiers' => $relatedIdentifiers,
            'fundingReferences' => $fundingReferences,
        ]);

        $this->titleTypeDbSlugSet = $titleTypeDbSlugSet;
    }

    /**
     * Normalize raw DataCite rights input while keeping drafts lenient.
     *
     * Drafts do not require rights, but when an upload already supplied them we
     * keep the same cleaned structure that StoreResourceRequest uses so a later
     * final save can persist the statements without reparsing the upload.
     *
     * @return array<int, array<string, string>>|mixed
     */
    private function normalizeRawRightsInput(mixed $rawRightsInput): mixed
    {
        if (! is_array($rawRightsInput)) {
            return $rawRightsInput;
        }

        $rawRights = [];

        foreach ($rawRightsInput as $statement) {
            if (! is_array($statement)) {
                continue;
            }

            $normalized = [
                'rights' => $this->rightsStringValue($statement, ['rights', 'rights_text']),
                'rightsUri' => $this->rightsStringValue($statement, ['rightsUri', 'rightsURI', 'rights_uri']),
                'rightsIdentifier' => $this->rightsStringValue($statement, ['rightsIdentifier', 'rights_identifier']),
                'rightsIdentifierScheme' => $this->rightsStringValue($statement, ['rightsIdentifierScheme', 'rights_identifier_scheme']),
                'schemeUri' => $this->rightsStringValue($statement, ['schemeUri', 'schemeURI', 'scheme_uri']),
                'lang' => $this->rightsStringValue($statement, ['lang', 'language']),
                'source' => $this->rightsStringValue($statement, ['source']),
            ];

            $normalized = array_filter(
                $normalized,
                fn (?string $value): bool => $value !== null,
            );

            if ($normalized !== []) {
                $rawRights[] = $normalized;
            }
        }

        return $rawRights;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @param  list<string>  $keys
     */
    private function rightsStringValue(array $statement, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $statement)) {
                continue;
            }

            $value = $statement[$key];

            if ($value === null || is_array($value) || is_object($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * Custom validation messages with section-prefixed context (Issue #605).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Resource Information
            'year.integer' => '[Resource Information] Publication Year must be a number.',
            'year.between' => '[Resource Information] Publication Year must be between :min and :max.',
            'resourceType.integer' => '[Resource Information] Resource Type must be a valid selection.',
            'resourceType.exists' => '[Resource Information] The selected Resource Type is invalid.',
            'version.max' => '[Resource Information] Version exceeds the maximum length of :max characters.',
            'language.exists' => '[Resource Information] The selected Language is invalid.',
            'doi.unique' => '[Resource Information] This DOI is already in use by another resource.',
            'doi.max' => '[Resource Information] DOI exceeds the maximum length of :max characters.',
            'titles.required' => '[Resource Information] At least one title is required.',
            'titles.min' => '[Resource Information] At least one title is required.',
            'titles.*.title.required' => '[Resource Information] Title #:position must not be empty.',
            'titles.*.title.max' => '[Resource Information] Title #:position exceeds the maximum length of :max characters.',
            'titles.*.titleType.required' => '[Resource Information] Title #:position must have a type.',

            // Licenses & Rights
            'licenses.*.exists' => '[Licenses & Rights] License #:position is not a recognized license.',
            'licenses.*.distinct' => '[Licenses & Rights] License #:position is a duplicate.',

            // Authors
            'authors.*.type.required' => '[Authors] Author #:position must have a type (person or institution).',
            'authors.*.type.in' => '[Authors] Author #:position has an invalid type.',
            'authors.*.email.email' => '[Authors] Author #:position has an invalid email address.',
            'authors.*.website.url' => '[Authors] Author #:position has an invalid website URL.',

            // Contributors
            'contributors.*.type.required' => '[Contributors] Contributor #:position must have a type (person or institution).',
            'contributors.*.type.in' => '[Contributors] Contributor #:position has an invalid type.',
            'contributors.*.roles.array' => '[Contributors] Contributor #:position roles must be a valid list.',
            'contributors.*.roles.*.required' => '[Contributors] Contributor #:position has an empty role.',
            'contributors.*.roles.*.string' => '[Contributors] Contributor #:position roles must contain only text values.',
            'contributors.*.roles.*.max' => '[Contributors] Contributor #:position role exceeds the maximum length.',
            'contributors.*.email.email' => '[Contributors] Contributor #:position has an invalid email address.',
            'contributors.*.website.url' => '[Contributors] Contributor #:position has an invalid website URL.',

            // Descriptions
            'descriptions.*.description.required' => '[Descriptions] Description #:position must not be empty.',
            'descriptions.*.descriptionType.required' => '[Descriptions] Description #:position must have a type.',
            'descriptions.*.descriptionType.in' => '[Descriptions] Description #:position has an invalid type.',

            // Dates
            'dates.*.dateType.required' => '[Dates] Date #:position must have a type.',
            'dates.*.dateType.in' => '[Dates] Date #:position has an invalid type.',
            'dates.*.startDate.date' => '[Dates] Date #:position has an invalid start date.',
            'dates.*.endDate.date' => '[Dates] Date #:position has an invalid end date.',

            // Controlled Vocabularies
            'gcmdKeywords.*.id.required' => '[Controlled Vocabularies] Keyword #:position must have an identifier.',
            'gcmdKeywords.*.text.required' => '[Controlled Vocabularies] Keyword #:position must have a label.',
            'gcmdKeywords.*.scheme.required' => '[Controlled Vocabularies] Keyword #:position must have a scheme.',

            // Free Keywords
            'freeKeywords.*.max' => '[Free Keywords] Keyword #:position exceeds the maximum length of :max characters.',

            // Spatial & Temporal Coverage
            'spatialTemporalCoverages.*.type.required' => '[Spatial & Temporal Coverage] Coverage #:position must have a type.',
            'spatialTemporalCoverages.*.type.in' => '[Spatial & Temporal Coverage] Coverage #:position has an invalid type.',
            'spatialTemporalCoverages.*.latMin.between' => '[Spatial & Temporal Coverage] Coverage #:position has an invalid minimum latitude (must be between -90 and 90).',
            'spatialTemporalCoverages.*.latMax.between' => '[Spatial & Temporal Coverage] Coverage #:position has an invalid maximum latitude (must be between -90 and 90).',
            'spatialTemporalCoverages.*.lonMin.between' => '[Spatial & Temporal Coverage] Coverage #:position has an invalid minimum longitude (must be between -180 and 180).',
            'spatialTemporalCoverages.*.lonMax.between' => '[Spatial & Temporal Coverage] Coverage #:position has an invalid maximum longitude (must be between -180 and 180).',

            // Related Work
            'relatedIdentifiers.*.identifier.required' => '[Related Work] Related identifier #:position must not be empty.',
            'relatedIdentifiers.*.identifierType.required' => '[Related Work] Related identifier #:position must have a type.',
            'relatedIdentifiers.*.relationType.required' => '[Related Work] Related identifier #:position must have a relation type.',

            // Funding References
            'fundingReferences.*.funderName.required' => '[Funding References] Funding reference #:position requires a funder name.',
            'fundingReferences.*.awardUri.url' => '[Funding References] Funding reference #:position has an invalid award URI.',

            // MSL Laboratories
            'mslLaboratories.*.identifier.required' => '[MSL Laboratories] Laboratory #:position must have an identifier.',
            'mslLaboratories.*.name.required' => '[MSL Laboratories] Laboratory #:position must have a name.',

            // Used Instruments
            'instruments.*.pid.required' => '[Used Instruments] Instrument #:position requires a PID.',
            'instruments.*.name.required' => '[Used Instruments] Instrument #:position requires a name.',
            'instruments.*.pidType.required' => '[Used Instruments] Instrument #:position requires a PID type.',
            'instruments.*.pidType.in' => '[Used Instruments] Instrument #:position has an invalid PID type.',

            // Datacenters
            'datacenters.*.exists' => '[Resource Information] Datacenter #:position is not a valid datacenter.',
            'datacenters.*.distinct' => '[Resource Information] Datacenter #:position is a duplicate.',
        ];
    }

    /**
     * After-validation hooks — structural checks with minimal mandatory field enforcement.
     *
     * Unlike StoreResourceRequest, this does NOT require:
     * - At least one Abstract description
     * - At least one Author with valid fields
     *
     * It DOES still validate:
     * - Title type slugs against DB
     * - Main Title must exist (at least one)
     * - Person authors must have lastName if provided
     * - Contributors must have proper structure if provided
     * - Polygon coverages must have at least 3 points
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            // Validate title type slugs against DB
            function (Validator $validator): void {
                /** @var mixed $candidateTitles */
                $candidateTitles = $this->input('titles', []);

                if (! is_array($candidateTitles)) {
                    return;
                }

                foreach ($candidateTitles as $index => $title) {
                    if (! is_array($title)) {
                        continue;
                    }

                    $candidate = $title['titleType'] ?? null;
                    if (! is_string($candidate) || trim($candidate) === '') {
                        continue;
                    }

                    $normalized = Str::kebab($candidate);
                    if ($normalized === 'main-title') {
                        continue;
                    }

                    if (! isset($this->titleTypeDbSlugSet[$candidate])) {
                        $validator->errors()->add(
                            "titles.$index.titleType",
                            '[Resource Information] Title #'.($index + 1).' has an unknown title type. Please select a valid title type.',
                        );
                    }
                }
            },
            // Require at least one Main Title
            function (Validator $validator): void {
                /** @var mixed $candidateTitles */
                $candidateTitles = $this->input('titles', []);

                $hasMainTitle = false;

                foreach ($candidateTitles as $title) {
                    if (! is_array($title)) {
                        continue;
                    }

                    $candidate = $title['titleType'] ?? null;
                    if (is_string($candidate) && Str::kebab($candidate) === 'main-title') {
                        $hasMainTitle = true;
                        break;
                    }
                }

                if (! $hasMainTitle) {
                    $validator->errors()->add(
                        'titles',
                        '[Resource Information] At least one title must be provided as a Main Title.',
                    );
                }
            },
            // Validate polygon coverages have at least 3 points
            function (Validator $validator): void {
                $coverages = $this->input('spatialTemporalCoverages', []);

                if (! is_array($coverages)) {
                    return;
                }

                foreach ($coverages as $index => $coverage) {
                    if (! is_array($coverage)) {
                        continue;
                    }

                    $type = $coverage['type'] ?? 'point';

                    if ($type === 'polygon') {
                        $polygonPoints = $coverage['polygonPoints'] ?? [];

                        if (! is_array($polygonPoints) || count($polygonPoints) < 3) {
                            $validator->errors()->add(
                                "spatialTemporalCoverages.$index.polygonPoints",
                                '[Spatial & Temporal Coverage] Coverage #'.($index + 1).' polygon must have at least 3 points.',
                            );
                        }
                    }
                }
            },
        ];
    }

    /**
     * Normalize a DOI input value: trim, strip URL prefix, lowercase — or return null.
     */
    private function normalizeDoiInput(mixed $input): mixed
    {
        if ($input === null) {
            return null;
        }

        if (is_numeric($input)) {
            $input = (string) $input;
        }

        if (! is_string($input)) {
            return $input;
        }

        $normalized = app(DoiSuggestionService::class)->normalizeDoi($input);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * Keep invalid typed entries so validation can report them, but drop draft
     * rows that cannot be represented by the current creator tables yet.
     *
     * @param  array<string, mixed>  $author
     */
    private function shouldKeepDraftAuthor(array $author): bool
    {
        $type = $author['type'] ?? null;

        if ($type === 'person') {
            return $this->normalizeString($author['lastName'] ?? null) !== null;
        }

        if ($type === 'institution') {
            return $this->normalizeString($author['institutionName'] ?? null) !== null;
        }

        return true;
    }

    /**
     * Keep invalid typed entries so validation can report them, but drop draft
     * rows that cannot be represented by the current contributor tables yet.
     *
     * @param  array<string, mixed>  $contributor
     */
    private function shouldKeepDraftContributor(array $contributor): bool
    {
        $type = $contributor['type'] ?? null;

        if ($type === 'person') {
            return $this->normalizeString($contributor['lastName'] ?? null) !== null;
        }

        if ($type === 'institution') {
            return $this->normalizeString($contributor['institutionName'] ?? null) !== null;
        }

        return true;
    }
}
