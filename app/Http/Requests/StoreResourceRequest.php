<?php

namespace App\Http\Requests;

use App\Support\BooleanNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Rule|string>>
     */
    public function rules(): array
    {
        return [
            'resourceId' => ['nullable', 'integer', Rule::exists('resources', 'id')],
            'doi' => ['nullable', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1000,9999'],
            'resourceType' => ['required', 'integer', Rule::exists('resource_types', 'id')],
            'version' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', Rule::exists('languages', 'code')],
            'titles' => ['required', 'array', 'min:1'],
            'titles.*.title' => ['required', 'string', 'max:255'],
            'titles.*.titleType' => ['required', 'string', Rule::exists('title_types', 'slug')],
            'licenses' => ['required', 'array', 'min:1'],
            'licenses.*' => ['string', 'distinct', Rule::exists('licenses', 'identifier')],
            'authors' => ['required', 'array', 'min:1'],
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
            'authors.*.affiliations' => ['array'],
            'authors.*.affiliations.*.value' => ['required', 'string', 'max:255'],
            'authors.*.affiliations.*.rorId' => ['nullable', 'string', 'max:255'],
            'contributors' => ['nullable', 'array'],
            'contributors.*.type' => ['required', Rule::in(['person', 'institution'])],
            'contributors.*.position' => ['required', 'integer', 'min:0'],
            'contributors.*.roles' => ['required', 'array', 'min:1'],
            'contributors.*.roles.*' => ['required', 'string', 'max:255'],
            'contributors.*.orcid' => ['nullable', 'string', 'max:255'],
            'contributors.*.firstName' => ['nullable', 'string', 'max:255'],
            'contributors.*.lastName' => ['nullable', 'string', 'max:255'],
            'contributors.*.institutionName' => ['nullable', 'string', 'max:255'],
            'contributors.*.affiliations' => ['array'],
            'contributors.*.affiliations.*.value' => ['required', 'string', 'max:255'],
            'contributors.*.affiliations.*.rorId' => ['nullable', 'string', 'max:255'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.*.descriptionType' => [
                'required',
                'string',
                Rule::in(['abstract', 'methods', 'series-information', 'table-of-contents', 'technical-info', 'other']),
            ],
            'descriptions.*.description' => ['required', 'string'],
            'dates' => ['nullable', 'array'],
            'dates.*.dateType' => [
                'required',
                'string',
                Rule::in(['accepted', 'available', 'collected', 'copyrighted', 'created', 'issued', 'submitted', 'updated', 'valid', 'withdrawn', 'other']),
            ],
            'dates.*.startDate' => ['nullable', 'date'],
            'dates.*.endDate' => ['nullable', 'date'],
            'dates.*.dateInformation' => ['nullable', 'string', 'max:255'],
            'freeKeywords' => ['nullable', 'array'],
            'freeKeywords.*' => ['string', 'max:255'],
            'gcmdKeywords' => ['nullable', 'array'],
            'gcmdKeywords.*.id' => ['required', 'string', 'max:512'],
            'gcmdKeywords.*.text' => ['required', 'string', 'max:255'],
            'gcmdKeywords.*.path' => ['required', 'string'],
            'gcmdKeywords.*.language' => ['nullable', 'string', 'max:10'],
            'gcmdKeywords.*.scheme' => ['nullable', 'string', 'max:255'],
            'gcmdKeywords.*.schemeURI' => ['nullable', 'string', 'max:512'],
            'gcmdKeywords.*.vocabularyType' => ['required', 'string', Rule::in(['science', 'platforms', 'instruments'])],
            'spatialTemporalCoverages' => ['nullable', 'array'],
            'spatialTemporalCoverages.*.latMin' => ['nullable', 'numeric', 'between:-90,90'],
            'spatialTemporalCoverages.*.latMax' => ['nullable', 'numeric', 'between:-90,90'],
            'spatialTemporalCoverages.*.lonMin' => ['nullable', 'numeric', 'between:-180,180'],
            'spatialTemporalCoverages.*.lonMax' => ['nullable', 'numeric', 'between:-180,180'],
            'spatialTemporalCoverages.*.startDate' => ['nullable', 'date'],
            'spatialTemporalCoverages.*.endDate' => ['nullable', 'date'],
            'spatialTemporalCoverages.*.startTime' => ['nullable', 'date_format:H:i:s,H:i'],
            'spatialTemporalCoverages.*.endTime' => ['nullable', 'date_format:H:i:s,H:i'],
            'spatialTemporalCoverages.*.timezone' => ['nullable', 'string', 'max:100'],
            'spatialTemporalCoverages.*.description' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<int, array<string, mixed>|mixed> $rawTitles */
        $rawTitles = $this->input('titles', []);

        $titles = [];

        foreach ($rawTitles as $title) {
            if (! is_array($title)) {
                $title = [];
            }

            $titles[] = [
                'title' => isset($title['title']) ? trim((string) $title['title']) : null,
                'titleType' => isset($title['titleType']) ? trim((string) $title['titleType']) : null,
            ];
        }

        /** @var array<int, mixed> $rawLicenses */
        $rawLicenses = $this->input('licenses', []);

        $licenses = [];

        foreach ($rawLicenses as $license) {
            $normalized = trim((string) $license);

            if ($normalized === '' || in_array($normalized, $licenses, true)) {
                continue;
            }

            $licenses[] = $normalized;
        }

        /** @var array<int, array<string, mixed>|mixed> $rawAuthors */
        $rawAuthors = $this->input('authors', []);

        $authors = [];

        foreach ($rawAuthors as $index => $author) {
            if (! is_array($author)) {
                continue;
            }

            $typeCandidate = isset($author['type']) ? trim((string) $author['type']) : '';
            $type = in_array($typeCandidate, ['person', 'institution'], true) ? $typeCandidate : 'person';

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

                        $key = $value . '|';

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

                    $key = $normalizedValue . '|' . ($normalizedRorId ?? '');

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

            if ($type === 'institution') {
                $authors[] = [
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

            $authors[] = [
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

        /** @var array<int, array<string, mixed>|mixed> $rawContributors */
        $rawContributors = $this->input('contributors', []);

        $contributors = [];

        foreach ($rawContributors as $index => $contributor) {
            if (! is_array($contributor)) {
                continue;
            }

            $typeCandidate = isset($contributor['type']) ? trim((string) $contributor['type']) : '';
            $type = in_array($typeCandidate, ['person', 'institution'], true) ? $typeCandidate : 'person';

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

                        $key = $value . '|';

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

                    $key = $normalizedValue . '|' . ($normalizedRorId ?? '');

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
            $roles = [];
            $rawRoles = $contributor['roles'] ?? [];

            if (is_array($rawRoles)) {
                foreach ($rawRoles as $role) {
                    $normalizedRole = trim((string) $role);
                    if ($normalizedRole !== '') {
                        $roles[] = $normalizedRole;
                    }
                }
            }

            if ($type === 'institution') {
                $contributors[] = [
                    'type' => 'institution',
                    'institutionName' => $this->normalizeString($contributor['institutionName'] ?? null),
                    'roles' => $roles,
                    'affiliations' => $affiliations,
                    'position' => (int) $index,
                ];

                continue;
            }

            $contributors[] = [
                'type' => 'person',
                'orcid' => $this->normalizeString($contributor['orcid'] ?? null),
                'firstName' => $this->normalizeString($contributor['firstName'] ?? null),
                'lastName' => $this->normalizeString($contributor['lastName'] ?? null),
                'roles' => $roles,
                'affiliations' => $affiliations,
                'position' => (int) $index,
            ];
        }

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

            // Convert to kebab-case for database storage
            $normalizedType = \Illuminate\Support\Str::kebab($descriptionType);

            $descriptions[] = [
                'descriptionType' => $normalizedType,
                'description' => $descriptionText,
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

            // Convert to kebab-case for database storage (if needed)
            $normalizedType = \Illuminate\Support\Str::kebab($dateType);

            $dates[] = [
                'dateType' => $normalizedType,
                'startDate' => $startDate !== '' ? $startDate : null,
                'endDate' => $endDate !== '' ? $endDate : null,
                'dateInformation' => $dateInformation !== '' ? $dateInformation : null,
            ];
        }

        $this->merge([
            'doi' => $this->filled('doi') ? trim((string) $this->input('doi')) : null,
            'year' => $this->filled('year') ? (int) $this->input('year') : null,
            'resourceType' => $this->filled('resourceType') ? (int) $this->input('resourceType') : null,
            'version' => $this->filled('version') ? trim((string) $this->input('version')) : null,
            'language' => $this->filled('language') ? trim((string) $this->input('language')) : null,
            'titles' => $titles,
            'licenses' => $licenses,
            'resourceId' => $this->filled('resourceId') ? (int) $this->input('resourceId') : null,
            'authors' => $authors,
            'contributors' => $contributors,
            'descriptions' => $descriptions,
            'dates' => $dates,
        ]);
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var mixed $candidateTitles */
                $candidateTitles = $this->input('titles', []);

                $hasMainTitle = false;

                foreach ($candidateTitles as $title) {
                    if (! is_array($title)) {
                        continue;
                    }

                    if (($title['titleType'] ?? null) === 'main-title') {
                        $hasMainTitle = true;
                        break;
                    }
                }

                if (! $hasMainTitle) {
                    $validator->errors()->add(
                        'titles',
                        'At least one title must be provided as a Main Title.',
                    );
                }
            },
            function (Validator $validator): void {
                /** @var mixed $candidateAuthors */
                $candidateAuthors = $this->input('authors', []);

                if (! is_array($candidateAuthors) || count($candidateAuthors) === 0) {
                    $validator->errors()->add(
                        'authors',
                        'At least one author must be provided.',
                    );

                    return;
                }

                foreach ($candidateAuthors as $index => $author) {
                    if (! is_array($author)) {
                        $validator->errors()->add(
                            "authors.$index",
                            'Each author entry must be an object.',
                        );

                        continue;
                    }

                    $type = $author['type'] ?? 'person';

                    if ($type === 'person') {
                        if (empty($author['lastName'])) {
                            $validator->errors()->add(
                                "authors.$index.lastName",
                                'A last name is required for person authors.',
                            );
                        }

                        $isContact = BooleanNormalizer::isTrue($author['isContact'] ?? false);
                        $email = $author['email'] ?? null;

                        if ($isContact && ($email === null || $email === '')) {
                            $validator->errors()->add(
                                "authors.$index.email",
                                'A contact email is required when marking an author as the contact person.',
                            );
                        }

                        continue;
                    }

                    if (empty($author['institutionName'])) {
                        $validator->errors()->add(
                            "authors.$index.institutionName",
                            'An institution name is required for institution authors.',
                        );
                    }
                }
            },
            function (Validator $validator): void {
                /** @var mixed $candidateContributors */
                $candidateContributors = $this->input('contributors', []);

                if (! is_array($candidateContributors)) {
                    return;
                }

                foreach ($candidateContributors as $index => $contributor) {
                    if (! is_array($contributor)) {
                        $validator->errors()->add(
                            "contributors.$index",
                            'Each contributor entry must be an object.',
                        );

                        continue;
                    }

                    $type = $contributor['type'] ?? 'person';

                    if ($type === 'person') {
                        if (empty($contributor['lastName'])) {
                            $validator->errors()->add(
                                "contributors.$index.lastName",
                                'A last name is required for person contributors.',
                            );
                        }
                    } else {
                        if (empty($contributor['institutionName'])) {
                            $validator->errors()->add(
                                "contributors.$index.institutionName",
                                'An institution name is required for institution contributors.',
                            );
                        }
                    }

                    $roles = $contributor['roles'] ?? [];
                    if (! is_array($roles) || count($roles) === 0) {
                        $validator->errors()->add(
                            "contributors.$index.roles",
                            'At least one role must be provided for each contributor.',
                        );
                    }
                }
            },
            function (Validator $validator): void {
                // Validate that at least one Abstract description exists
                $descriptions = $this->input('descriptions', []);
                $hasAbstract = false;

                if (is_array($descriptions)) {
                    foreach ($descriptions as $description) {
                        if (is_array($description) && 
                            isset($description['descriptionType']) && 
                            $description['descriptionType'] === 'abstract' &&
                            isset($description['description']) &&
                            is_string($description['description']) &&
                            trim($description['description']) !== '') {
                            $hasAbstract = true;
                            break;
                        }
                    }
                }

                if (! $hasAbstract) {
                    $validator->errors()->add(
                        'descriptions',
                        'An Abstract description is required.',
                    );
                }
            },
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }
}
