<?php

declare(strict_types=1);

namespace App\Services\Editor;

use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceDate;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Service to transform Resource models into editor-compatible data format.
 *
 * This service extracts transformation logic from the editor route,
 * providing a clean separation of concerns.
 */
class EditorDataTransformer
{
    /**
     * Map description type slugs to frontend format.
     *
     * @var array<string, string>
     */
    private const DESCRIPTION_TYPE_MAP = [
        'abstract' => 'Abstract',
        'methods' => 'Methods',
        'series-information' => 'SeriesInformation',
        'table-of-contents' => 'TableOfContents',
        'technical-info' => 'TechnicalInfo',
        'other' => 'Other',
    ];

    /**
     * Transform a Resource model into editor-compatible data.
     *
     * @return array<string, mixed>
     */
    public function transformResource(Resource $resource): array
    {
        // Cache creators transform to avoid duplicate computation
        $creators = $this->transformCreators($resource);

        return [
            'doi' => $resource->doi ?? '',
            'year' => (string) $resource->publication_year,
            'version' => $resource->version ?? '',
            'language' => $resource->language->code ?? '',
            'resourceType' => (string) $resource->resource_type_id,
            'resourceId' => (string) $resource->id,
            'titles' => $this->transformTitles($resource),
            'initialLicenses' => $this->transformLicenses($resource),
            'authors' => $creators['authors'],
            'contributors' => $creators['contributors'],
            'descriptions' => $this->transformDescriptions($resource),
            'dates' => $this->transformDates($resource),
            'gcmdKeywords' => $this->transformGcmdKeywords($resource),
            'freeKeywords' => $this->transformFreeKeywords($resource),
            'coverages' => $this->transformCoverages($resource),
            'relatedWorks' => $this->transformRelatedIdentifiers($resource),
            'fundingReferences' => $this->transformFundingReferences($resource),
            'mslLaboratories' => $this->transformMslLaboratories($resource),
        ];
    }

    /**
     * Get common editor props (settings, API keys).
     *
     * @return array<string, mixed>
     */
    public function getCommonProps(): array
    {
        return [
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
            'googleMapsApiKey' => config('services.google_maps.api_key'),
        ];
    }

    /**
     * Transform resource titles to frontend format.
     *
     * @return array<int, array{title: string, titleType: string}>
     */
    public function transformTitles(Resource $resource): array
    {
        return $resource->titles->map(function ($title): array {
            // Frontend uses kebab-case slugs; main title is represented as 'main-title'
            // Use null-safe operator for legacy data where titleType may be null
            $titleType = $title->isMainTitle()
                ? 'main-title'
                // @phpstan-ignore nullsafe.neverNull (titleType may be null in legacy data before migration)
                : Str::kebab($title->titleType?->slug ?? 'other');

            return [
                'title' => $title->value,
                'titleType' => $titleType,
            ];
        })->toArray();
    }

    /**
     * Transform resource licenses to frontend format.
     *
     * @return array<int, string>
     */
    public function transformLicenses(Resource $resource): array
    {
        return $resource->rights->pluck('identifier')->toArray();
    }

    /**
     * Transform resource creators to authors and contributors format.
     *
     * @return array{authors: array<int, array<string, mixed>>, contributors: array<int, array<string, mixed>>}
     */
    public function transformCreators(Resource $resource): array
    {
        // Group ResourceCreator entries by their creatorable (Person/Institution)
        // to handle cases where same person has multiple ResourceCreator records
        $creatorableGroups = $resource->creators
            ->filter(function ($creator): bool {
                // Filter out MSL laboratories
                if ($creator->creatorable_type === Institution::class) {
                    /** @var Institution $institution */
                    $institution = $creator->creatorable;

                    return $institution->name_identifier_scheme !== 'labid';
                }

                return true;
            })
            ->groupBy(function ($creator): string {
                return $creator->creatorable_type.'_'.$creator->creatorable_id;
            });

        $authors = [];
        $contributors = [];

        foreach ($creatorableGroups as $group) {
            // In DataCite 4.6, all ResourceCreator entries are creators (no role distinction)
            /** @var \App\Models\ResourceCreator $firstEntry */
            $firstEntry = $group->first();
            $creatorable = $firstEntry->creatorable;

            // Collect all unique affiliations from all entries of this creator
            $allAffiliations = $group->flatMap(function ($creator) {
                return $creator->affiliations;
            })->unique(function ($affiliation): string {
                // Unique by name and identifier combination
                return $affiliation->name.'|'.($affiliation->identifier ?? 'null');
            });

            // All ResourceCreator entries are creators in DataCite 4.6
            $data = [
                'position' => $firstEntry->position,
                'isContact' => false, // Contact tracking will be handled differently
            ];

            if ($firstEntry->creatorable_type === Person::class) {
                /** @var Person $creatorable */
                $data['type'] = 'person';
                // Map to frontend field names
                $data['firstName'] = $creatorable->given_name ?? '';
                $data['lastName'] = $creatorable->family_name ?? '';
                $data['orcid'] = $creatorable->name_identifier ?? '';
            } elseif ($firstEntry->creatorable_type === Institution::class) {
                /** @var Institution $creatorable */
                $data['type'] = 'institution';
                $data['institutionName'] = $creatorable->name ?? '';
                $data['rorId'] = $creatorable->name_identifier ?? '';
            }

            // Add unique affiliations - map to frontend field names
            $data['affiliations'] = $allAffiliations->map(fn (Affiliation $affiliation): array => [
                'value' => $affiliation->name,
                'rorId' => $affiliation->identifier,
            ])->values()->toArray();

            $authors[] = $data;
        }

        // Transform ResourceContributor entries to contributors format
        foreach ($resource->contributors as $contributor) {
            /** @var \App\Models\ResourceContributor $contributor */
            $data = [
                'position' => $contributor->position,
                // @phpstan-ignore nullCoalesce.expr (defensive coding for data integrity)
                'contributorType' => $contributor->contributorType?->name ?? 'Other',
            ];

            if ($contributor->contributorable_type === Person::class) {
                /** @var Person $person */
                $person = $contributor->contributorable;
                $data['type'] = 'person';
                $data['firstName'] = $person->given_name ?? '';
                $data['lastName'] = $person->family_name ?? '';
                $data['orcid'] = $person->name_identifier ?? '';
            } elseif ($contributor->contributorable_type === Institution::class) {
                /** @var Institution $institution */
                $institution = $contributor->contributorable;
                $data['type'] = 'institution';
                $data['institutionName'] = $institution->name ?? '';
                $data['rorId'] = $institution->name_identifier ?? '';
            }

            // Add affiliations from the contributor
            $data['affiliations'] = $contributor->affiliations->map(fn (Affiliation $affiliation): array => [
                'value' => $affiliation->name,
                'rorId' => $affiliation->identifier,
            ])->values()->toArray();

            $contributors[] = $data;
        }

        // Sort by position
        usort($authors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        usort($contributors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return [
            'authors' => $authors,
            'contributors' => $contributors,
        ];
    }

    /**
     * Transform resource descriptions to frontend format.
     *
     * The database stores description type slugs in PascalCase (e.g., 'Abstract', 'SeriesInformation')
     * because they were seeded from the DataCite schema's camelCase naming convention. The frontend
     * expects specific display names (e.g., 'Abstract', 'Series Information'), so we:
     * 1. Convert PascalCase to kebab-case for consistent DESCRIPTION_TYPE_MAP lookup
     * 2. Map to the frontend display format which uses PascalCase with spaces
     *
     * This round-trip conversion exists because the database schema predates the frontend naming
     * conventions. A future migration could normalize database slugs to kebab-case to simplify
     * this logic.
     *
     * @return array<int, array{type: string, description: string}>
     */
    public function transformDescriptions(Resource $resource): array
    {
        return $resource->descriptions->map(function ($description): array {
            // Map description_type slug to frontend format
            // Use Str::kebab() to normalize slugs since DB stores PascalCase (e.g., 'SeriesInformation' → 'series-information')
            // @phpstan-ignore nullCoalesce.expr (defensive coding)
            $typeSlug = Str::kebab($description->descriptionType?->slug ?? 'other');
            $frontendType = self::DESCRIPTION_TYPE_MAP[$typeSlug] ?? 'Other';

            return [
                'type' => $frontendType,
                'description' => $description->value,
            ];
        })->toArray();
    }

    /**
     * Transform resource dates to frontend format.
     *
     * Excludes 'coverage', 'created', and 'updated' dates as they are handled separately.
     * Preserves full ISO 8601 datetime+timezone values for dates that include time components.
     *
     * @return array<int, array{dateType: string, startDate: string, endDate: string}>
     */
    public function transformDates(Resource $resource): array
    {
        return $resource->dates
            ->filter(function (ResourceDate $date): bool {
                // Use null-safe operator to handle missing dateType relationship
                // @phpstan-ignore nullCoalesce.expr (defensive coding for data integrity)
                $slug = $date->dateType?->slug ?? '';

                return ! in_array($slug, ['coverage', 'created', 'updated'], true);
            })
            ->map(function (ResourceDate $date): array {
                return [
                    // Use null-safe operator to handle missing dateType relationship
                    // @phpstan-ignore nullCoalesce.expr (defensive coding for data integrity)
                    'dateType' => $date->dateType?->slug ?? '',
                    'startDate' => $this->formatStoredDate($date->start_date),
                    'endDate' => $this->formatStoredDate($date->end_date),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Format a stored date value for frontend consumption.
     *
     * Preserves full ISO 8601 datetime+timezone values (e.g., "2022-10-06T09:35+01:00")
     * instead of stripping time information. For legacy date-only values, formats as Y-m-d.
     *
     * @see https://github.com/McNamara84/ernie/issues/508
     */
    private function formatStoredDate(?string $dateValue): string
    {
        if (empty($dateValue)) {
            return '';
        }

        // If it contains a time component, preserve the full ISO 8601 value
        if (str_contains($dateValue, 'T')) {
            return $dateValue;
        }

        // Legacy date-only values: normalize via Carbon to ensure Y-m-d format
        try {
            return Carbon::parse($dateValue)->format('Y-m-d');
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Transform free keywords from subjects.
     *
     * @return array<int, string>
     */
    public function transformFreeKeywords(Resource $resource): array
    {
        return $resource->subjects
            ->filter(fn ($subject): bool => empty($subject->subject_scheme))
            ->pluck('value')
            ->toArray();
    }

    /**
     * Transform GCMD controlled keywords from subjects.
     *
     * @return array<int, array{id: string, text: string, path: string, scheme: string, schemeURI: string, language: string}>
     */
    public function transformGcmdKeywords(Resource $resource): array
    {
        return $resource->subjects
            ->filter(fn ($subject): bool => ! empty($subject->subject_scheme))
            ->map(function ($subject): array {
                return [
                    'id' => $subject->classification_code ?? '',
                    'text' => $subject->value,
                    'path' => $subject->value, // Path may need to be extracted from subject text
                    'scheme' => $subject->subject_scheme ?? '',
                    'schemeURI' => $subject->scheme_uri ?? '',
                    'language' => 'en',
                ];
            })->toArray();
    }

    /**
     * Transform geoLocations to coverages format for frontend.
     *
     * @return array<int, array<string, string>>
     */
    public function transformCoverages(Resource $resource): array
    {
        return $resource->geoLocations->map(function ($geoLocation): array {
            return [
                'id' => (string) $geoLocation->id,
                'latMin' => $geoLocation->south_bound_latitude !== null ? (string) $geoLocation->south_bound_latitude : '',
                'latMax' => $geoLocation->north_bound_latitude !== null ? (string) $geoLocation->north_bound_latitude : '',
                'lonMin' => $geoLocation->west_bound_longitude !== null ? (string) $geoLocation->west_bound_longitude : '',
                'lonMax' => $geoLocation->east_bound_longitude !== null ? (string) $geoLocation->east_bound_longitude : '',
                'startDate' => '',
                'endDate' => '',
                'startTime' => '',
                'endTime' => '',
                'timezone' => 'UTC',
                'description' => $geoLocation->place ?? '',
            ];
        })->toArray();
    }

    /**
     * Transform related identifiers to frontend format.
     *
     * @return array<int, array{identifier: string, identifier_type: string, relation_type: string}>
     */
    public function transformRelatedIdentifiers(Resource $resource): array
    {
        return $resource->relatedIdentifiers
            ->sortBy('position')
            ->map(fn (\App\Models\RelatedIdentifier $relatedId): array => [
                'identifier' => $relatedId->identifier,
                'identifier_type' => $relatedId->identifierType->name,
                'relation_type' => $relatedId->relationType->name,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Transform funding references to frontend format.
     *
     * @return array<int, array<string, string>>
     */
    public function transformFundingReferences(Resource $resource): array
    {
        return $resource->fundingReferences
            ->sortBy('position')
            ->map(function ($funding): array {
                return [
                    'funderName' => $funding->funder_name,
                    'funderIdentifier' => $funding->funder_identifier ?? '',
                    'funderIdentifierType' => $funding->funder_identifier_type ?? '',
                    'awardNumber' => $funding->award_number ?? '',
                    'awardUri' => $funding->award_uri ?? '',
                    'awardTitle' => $funding->award_title ?? '',
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Transform MSL Laboratories from creators with labid identifier scheme.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transformMslLaboratories(Resource $resource): array
    {
        return $resource->creators
            ->filter(function ($creator): bool {
                if ($creator->creatorable_type === Institution::class) {
                    /** @var Institution $institution */
                    $institution = $creator->creatorable;

                    return $institution->name_identifier_scheme === 'labid';
                }

                return false;
            })
            ->map(function ($creator): array {
                /** @var Institution $institution */
                $institution = $creator->creatorable;
                $affiliation = $creator->affiliations->first();

                return [
                    'identifier' => $institution->name_identifier ?? '',
                    'name' => $institution->name ?? '',
                    'affiliation_name' => $affiliation->name ?? '',
                    'affiliation_ror' => $affiliation->identifier ?? '',
                    'position' => $creator->position,
                ];
            })
            ->values()
            ->toArray();
    }
}
