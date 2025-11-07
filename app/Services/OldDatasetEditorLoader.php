<?php

namespace App\Services;

use App\Models\OldDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service to load and transform data from old SUMARIOPMD database for the editor.
 */
class OldDatasetEditorLoader
{
    private const DATASET_CONNECTION = 'metaworks';

    /**
     * Load complete dataset from old database and transform for editor.
     *
     * @param  int  $id  Old dataset ID
     * @return array<string, mixed> Transformed data ready for editor
     *
     * @throws \Exception If dataset not found or loading fails
     */
    public function loadForEditor(int $id): array
    {
        $dataset = OldDataset::find($id);

        if (! $dataset) {
            throw new \Exception("Old dataset with ID {$id} not found");
        }

        try {
            return [
                'doi' => $dataset->identifier ?? '',
                'year' => $dataset->publicationyear !== null ? (string) $dataset->publicationyear : '',
                'version' => $dataset->version ?? '',
                'language' => $this->mapLanguage($dataset->language),
                'resourceType' => $this->mapResourceType($dataset->resourcetypegeneral),
                'titles' => $this->loadTitles($dataset),
                'initialLicenses' => $this->loadLicenses($dataset),
                'authors' => $this->loadAuthors($id),
                'contributors' => $this->loadContributors($id),
                'descriptions' => $this->loadDescriptions($id),
                'dates' => $this->loadDates($id),
                'gcmdKeywords' => $this->loadControlledKeywords($id),
                'freeKeywords' => $this->loadFreeKeywords($dataset),
                'coverages' => $this->loadCoverages($id),
                'relatedWorks' => $this->loadRelatedIdentifiers($id),
                'fundingReferences' => $this->loadFundingReferences($id),
                'mslLaboratories' => $this->loadMslLaboratories($id),
            ];
        } catch (\Throwable $e) {
            Log::error("Failed to load old dataset {$id} for editor", [
                'dataset_id' => $id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception("Failed to load dataset from legacy database: {$e->getMessage()}");
        }
    }

    /**
     * Map old language code to new language code.
     */
    private function mapLanguage(?string $oldLanguage): string
    {
        if (empty($oldLanguage)) {
            return 'en'; // Default to English
        }

        // Old DB uses ISO 639-1 codes (en, de, etc.), new DB too
        // Just normalize to lowercase
        return strtolower($oldLanguage);
    }

    /**
     * Map old resource type to new resource type ID.
     */
    private function mapResourceType(?string $oldType): string
    {
        if (empty($oldType)) {
            return '1'; // Default to 'Dataset'
        }

        // Map old resource type strings to new IDs
        $typeMap = [
            'Dataset' => '1',
            'Collection' => '2',
            'Model' => '3',
            'Software' => '4',
            'Image' => '5',
            'PhysicalObject' => '6',
        ];

        return $typeMap[$oldType] ?? '1'; // Default to Dataset
    }

    /**
     * Load and transform titles from old dataset.
     *
     * @return list<array{title: string, titleType: string}>
     */
    private function loadTitles(OldDataset $dataset): array
    {
        $titles = [];

        // Main title
        if (! empty($dataset->title)) {
            $titles[] = [
                'title' => $dataset->title,
                'titleType' => '', // Empty = main title
            ];
        }

        // Additional titles from titles table (if any)
        $additionalTitles = DB::connection(self::DATASET_CONNECTION)
            ->table('title')
            ->where('resource_id', $dataset->id)
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->get();

        foreach ($additionalTitles as $title) {
            $titles[] = [
                'title' => $title->title,
                'titleType' => $this->mapTitleType($title->type ?? ''),
            ];
        }

        return $titles;
    }

    /**
     * Map old title type to new title type slug.
     */
    private function mapTitleType(?string $oldType): string
    {
        if (empty($oldType)) {
            return '';
        }

        $typeMap = [
            'AlternativeTitle' => 'alternative-title',
            'Subtitle' => 'subtitle',
            'TranslatedTitle' => 'translated-title',
            'Other' => 'other',
        ];

        return $typeMap[$oldType] ?? '';
    }

    /**
     * Load licenses from old dataset.
     *
     * @return list<string>
     */
    private function loadLicenses(OldDataset $dataset): array
    {
        $licenses = $dataset->getLicenses();

        return array_values($licenses);
    }

    /**
     * Load and transform authors from old dataset.
     *
     * @return list<array<string, mixed>>
     */
    private function loadAuthors(int $id): array
    {
        $authors = DB::connection(self::DATASET_CONNECTION)
            ->table('creator')
            ->where('resource_id', $id)
            ->orderBy('position')
            ->get();

        $result = [];

        foreach ($authors as $index => $author) {
            $data = [
                'type' => 'person',
                'position' => $index,
            ];

            if (! empty($author->givenname)) {
                $data['firstName'] = $author->givenname;
            }

            if (! empty($author->familyname)) {
                $data['lastName'] = $author->familyname;
            }

            // If no firstName/lastName, use full name as lastName
            if (empty($data['firstName']) && empty($data['lastName']) && ! empty($author->name)) {
                $data['lastName'] = $author->name;
            }

            if (! empty($author->orcid)) {
                $data['orcid'] = $author->orcid;
            }

            // Check if this is a contact person
            $data['isContact'] = (bool) ($author->iscontact ?? false);

            if ($data['isContact']) {
                if (! empty($author->email)) {
                    $data['email'] = $author->email;
                }

                if (! empty($author->website)) {
                    $data['website'] = $author->website;
                }
            }

            // Load affiliations for this author
            $data['affiliations'] = $this->loadAffiliations($id, $author->id ?? null, 'creator');

            $result[] = $data;
        }

        return $result;
    }

    /**
     * Load affiliations for an author or contributor.
     *
     * @param  int  $resourceId  Resource ID
     * @param  int|null  $personId  Creator or contributor ID
     * @param  string  $type  'creator' or 'contributor'
     * @return list<array{value: string, rorId: string|null}>
     */
    private function loadAffiliations(int $resourceId, ?int $personId, string $type): array
    {
        if ($personId === null) {
            return [];
        }

        $table = $type === 'creator' ? 'creatoraffiliation' : 'contributoraffiliation';
        $foreignKey = $type === 'creator' ? 'creator_id' : 'contributor_id';

        $affiliations = DB::connection(self::DATASET_CONNECTION)
            ->table($table)
            ->where('resource_id', $resourceId)
            ->where($foreignKey, $personId)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get();

        $result = [];

        foreach ($affiliations as $affiliation) {
            $result[] = [
                'value' => $affiliation->value,
                'rorId' => $affiliation->rorid ?? null,
            ];
        }

        return $result;
    }

    /**
     * Load and transform contributors from old dataset.
     *
     * @return list<array<string, mixed>>
     */
    private function loadContributors(int $id): array
    {
        $contributors = DB::connection(self::DATASET_CONNECTION)
            ->table('contributor')
            ->where('resource_id', $id)
            ->orderBy('position')
            ->get();

        $result = [];

        foreach ($contributors as $index => $contributor) {
            $contributorType = $contributor->contributortype ?? 'person';
            $type = strtolower($contributorType) === 'person' ? 'person' : 'institution';

            $data = [
                'type' => $type,
                'position' => $index,
            ];

            if ($type === 'person') {
                if (! empty($contributor->givenname)) {
                    $data['firstName'] = $contributor->givenname;
                }

                if (! empty($contributor->familyname)) {
                    $data['lastName'] = $contributor->familyname;
                }

                // If no firstName/lastName, use full name as lastName
                if (empty($data['firstName']) && empty($data['lastName']) && ! empty($contributor->name)) {
                    $data['lastName'] = $contributor->name;
                }

                if (! empty($contributor->orcid)) {
                    $data['orcid'] = $contributor->orcid;
                }
            } else {
                // Institution
                if (! empty($contributor->name)) {
                    $data['institutionName'] = $contributor->name;
                }
            }

            // Load contributor roles
            $data['roles'] = $this->loadContributorRoles($id, $contributor->id ?? null);

            // Load affiliations
            $data['affiliations'] = $this->loadAffiliations($id, $contributor->id ?? null, 'contributor');

            $result[] = $data;
        }

        return $result;
    }

    /**
     * Load roles for a contributor.
     *
     * @return list<string>
     */
    private function loadContributorRoles(int $resourceId, ?int $contributorId): array
    {
        if ($contributorId === null) {
            return [];
        }

        $roles = DB::connection(self::DATASET_CONNECTION)
            ->table('contributorrole')
            ->where('resource_id', $resourceId)
            ->where('contributor_id', $contributorId)
            ->whereNotNull('role')
            ->where('role', '!=', '')
            ->pluck('role')
            ->toArray();

        return array_values($roles);
    }

    /**
     * Load descriptions from old dataset.
     *
     * @return list<array{type: string, description: string}>
     */
    private function loadDescriptions(int $id): array
    {
        $dataset = OldDataset::find($id);

        if (! $dataset) {
            return [];
        }

        $descriptions = $dataset->getDescriptions();

        return array_values($descriptions);
    }

    /**
     * Load dates from old dataset.
     *
     * @return list<array{dateType: string, startDate: string, endDate: string}>
     */
    private function loadDates(int $id): array
    {
        $dataset = OldDataset::find($id);

        if (! $dataset) {
            return [];
        }

        $dates = $dataset->getResourceDates();

        return array_values($dates);
    }

    /**
     * Load controlled keywords (GCMD) from old dataset.
     *
     * @return list<array<string, mixed>>
     */
    private function loadControlledKeywords(int $id): array
    {
        // Get supported GCMD thesauri
        $supportedThesauri = OldDatasetKeywordTransformer::getSupportedThesauri();

        // Load keywords from old database
        $oldKeywords = DB::connection(self::DATASET_CONNECTION)
            ->table('thesauruskeyword as tk')
            ->join('thesaurusvalue as tv', function ($join) {
                $join->on('tk.keyword', '=', 'tv.keyword')
                    ->on('tk.thesaurus', '=', 'tv.thesaurus');
            })
            ->where('tk.resource_id', $id)
            ->whereIn('tk.thesaurus', $supportedThesauri)
            ->select('tv.keyword', 'tv.thesaurus', 'tv.uri', 'tv.description')
            ->get();

        // Transform to new format
        $keywords = OldDatasetKeywordTransformer::transformMany($oldKeywords->all());

        return array_values($keywords);
    }

    /**
     * Load free keywords from old dataset.
     *
     * @return list<string>
     */
    private function loadFreeKeywords(OldDataset $dataset): array
    {
        $keywordsString = $dataset->keywords;

        if (empty($keywordsString)) {
            return [];
        }

        $keywords = array_map(
            fn ($keyword) => trim($keyword),
            explode(',', $keywordsString)
        );

        // Remove empty strings
        $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');

        return array_values($keywords);
    }

    /**
     * Load spatial-temporal coverages from old dataset.
     *
     * @return list<array<string, mixed>>
     */
    private function loadCoverages(int $id): array
    {
        $dataset = OldDataset::find($id);

        if (! $dataset) {
            return [];
        }

        $coverages = $dataset->getCoverages();

        return array_values($coverages);
    }

    /**
     * Load related identifiers from old dataset.
     *
     * @return list<array{identifier: string, identifier_type: string, relation_type: string}>
     */
    private function loadRelatedIdentifiers(int $id): array
    {
        $dataset = OldDataset::find($id);

        if (! $dataset) {
            return [];
        }

        $identifiers = $dataset->getRelatedIdentifiers();

        // Transform to expected format (snake_case keys)
        return array_values(array_map(function ($identifier) {
            return [
                'identifier' => $identifier['identifier'],
                'identifier_type' => $identifier['identifierType'],
                'relation_type' => $identifier['relationType'],
            ];
        }, $identifiers));
    }

    /**
     * Load funding references from old dataset.
     *
     * @return list<array<string, mixed>>
     */
    private function loadFundingReferences(int $id): array
    {
        $dataset = OldDataset::find($id);

        if (! $dataset) {
            return [];
        }

        $funding = $dataset->getFundingReferences();

        return array_values($funding);
    }

    /**
     * Load MSL laboratories from old dataset.
     *
     * @return list<array<string, mixed>>
     */
    private function loadMslLaboratories(int $id): array
    {
        $dataset = OldDataset::find($id);

        if (! $dataset) {
            return [];
        }

        $laboratories = $dataset->getMslLaboratories();

        return array_values($laboratories);
    }
}
