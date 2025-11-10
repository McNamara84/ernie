<?php

namespace App\Services;

use App\Models\OldDataset;
use App\Support\NameParser;
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

        // Load titles from title table
        $dbTitles = DB::connection(self::DATASET_CONNECTION)
            ->table('title')
            ->where('resource_id', $dataset->id)
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->get();

        $hasMainTitle = false;

        foreach ($dbTitles as $title) {
            // If titletype is NULL, treat it as main title
            if (empty($title->titletype)) {
                $titles[] = [
                    'title' => $title->title,
                    'titleType' => 'main-title', // Main title
                ];
                $hasMainTitle = true;
            } else {
                $mappedType = $this->mapTitleType($title->titletype);
                $titles[] = [
                    'title' => $title->title,
                    'titleType' => $mappedType,
                ];
            }
        }

        // Fallback: If no titles in title table or no main title found, use resource.title
        if (! $hasMainTitle && ! empty($dataset->title)) {
            array_unshift($titles, [
                'title' => $dataset->title,
                'titleType' => 'main-title', // Main title
            ]);
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

        // Map old license names to new license identifiers
        $mappedLicenses = [];
        foreach ($licenses as $licenseName) {
            $identifier = $this->mapLicenseNameToIdentifier($licenseName);
            if ($identifier) {
                $mappedLicenses[] = $identifier;
            } else {
                Log::warning('Could not map license from old database', [
                    'dataset_id' => $dataset->id,
                    'license_name' => $licenseName,
                ]);
            }
        }

        return $mappedLicenses;
    }

    /**
     * Map old license name to new license identifier.
     *
     * @param  string  $licenseName  Old license name (e.g., "CC BY 4.0")
     * @return string|null New license identifier (e.g., "CC-BY-4.0") or null if not found
     */
    private function mapLicenseNameToIdentifier(string $licenseName): ?string
    {
        // Trim whitespace and normalize
        $licenseName = trim($licenseName);

        // Direct mappings for common licenses (sorted by frequency from analysis)
        $mappings = [
            // Creative Commons - Basic forms (most common: 1543 + 222 + 17 = 1782 datasets)
            'CC BY 4.0' => 'CC-BY-4.0',
            'CC BY-NC 4.0' => 'CC-BY-NC-4.0',
            'CC BY-SA 4.0' => 'CC-BY-SA-4.0',
            'CC BY 3.0' => 'CC-BY-3.0',
            'CC BY-NC 3.0' => 'CC-BY-NC-3.0',
            'CC BY-SA 3.0' => 'CC-BY-SA-3.0',
            'CC BY-NC-SA 4.0' => 'CC-BY-NC-SA-4.0',
            'CC BY-NC-SA 3.0' => 'CC-BY-NC-SA-3.0',
            'CC BY-NC' => 'CC-BY-NC-4.0',
            'CC BY NC 4.0' => 'CC-BY-NC-4.0',

            // CC0 variants (3 datasets)
            'CC0 1.0' => 'CC0-1.0',
            'CC0' => 'CC0-1.0',
            'CC0 Universal 1.0' => 'CC0-1.0',

            // Creative Commons - Full names (24 datasets)
            'Creative Commons Attribution 4.0 International' => 'CC-BY-4.0',
            'Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)' => 'CC-BY-NC-4.0',
            'Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)' => 'CC-BY-NC-4.0',
            'CC Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)' => 'CC-BY-NC-4.0',
            'CC Attribution-NonCommercial 4.0 (CC BY-NC 4.0)' => 'CC-BY-NC-4.0',
            'CC Attribution Non-Commercial 4.0 International (CC BY NC 4.0)' => 'CC-BY-NC-4.0',
            'CC Attribution 4.0 (CC BY 4.0)' => 'CC-BY-4.0',

            // Apache License variants (5 + 4 + 4 + 2 = 15+ datasets)
            'Apache License 2.0' => 'Apache-2.0',
            'Apache License Version 2.0' => 'Apache-2.0',
            'Apache License, version 2.0' => 'Apache-2.0',
            'Apache License, Version 2.0 (ALv2)' => 'Apache-2.0',
            'Apache Licence, Version 2.0, January 2004 (Copyright 2018 Monika Korte, Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences)' => 'Apache-2.0',
            'Apache Licence, Version 2.0, January 2004 (Copyright 2019 Monika Korte, Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences)' => 'Apache-2.0',

            // MIT License variants (7 + 2 + 2 + 1 = 12+ datasets)
            'MIT License' => 'MIT',
            'MIT Licence' => 'MIT',
            'MIT License, Copyright (c) 2023 Philipp C. Verpoort' => 'MIT',
            'MIT License Copyright 2023 Ngai-Ham (Erik) Chan' => 'MIT',
            'MIT License / Copyright © 2022  Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences' => 'MIT',
            'MIT Licence Copyright (c) <2024> the authors; GFZ Helmholtz Centre for Geosciences)' => 'MIT',
            'Software Licence: MIT License, Copyright © Copyright (C) 2012 David Kneis (david.kneis@uni-potsdam.de), 2015-2019 Tobias Pilz (tobias.pilz@pik-potsdam.de) (contributions shown by git commit history)' => 'MIT',

            // GNU General Public License v3 (8 + 4 + 3 + 3 + 2 + 2 + 2 + 1 = 25+ datasets)
            'GNU General Public License, version 3' => 'GPL-3.0-only',
            'GNU General Public License, Version 3, 29 June 2007' => 'GPL-3.0-only',

            // GNU Lesser General Public License (1 dataset)
            'GNU Lesser General Public License v2.1' => 'LGPL-2.1-only',
            'GNU Lesser General Public License v 2.1' => 'LGPL-2.1-only',
            'GNU Lesser General Public License Version 3 (29 June 2007)' => 'LGPL-3.0-only',
            'Well-Func.py: GNU Lesser General Public License, v. 3.0' => 'LGPL-3.0-only',

            // GNU Affero General Public License (2 + 1 = 3 datasets)
            'GNU Affero General Public License (AGPL) (Version 3, 19 November 2007)' => 'AGPL-3.0-only',
            'GNU Affero General Public License, Version 3, 19 November 2007, Copyright Potsdam Institute for Climate Impact Research' => 'AGPL-3.0-only',
            'GNU Affero General Public License, Version 3 (AGPL 3.0), 19 November 2007, Copyright Potsdam Institute for Climate Impact Research' => 'AGPL-3.0-only',
            'GNU Affero General Public Licence (Version 3, 19 November 2007)  Copyright (C) 2021 Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences' => 'AGPL-3.0-only',
            'GNU Affero General Public License, Version 3 (19 June 2007); Copyright © 2023 Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences, Potsdam, Germany (Riccardo Zaccarelli, Graeme Warherill)' => 'AGPL-3.0-only',

            // BSD Licenses (1 + 2 = 3 datasets)
            'BSD 2-clause "Simplified" License' => 'BSD-2-Clause',
            'BSD 3-clause License' => 'BSD-3-Clause',
            'BSD 3-Clause License' => 'BSD-3-Clause',
            'BSD-3 Clause License' => 'BSD-3-Clause',

            // EUPL Licenses (4 + 1 = 5 datasets)
            'EUPL v1.2' => 'EUPL-1.2',
            'EUPL-1.2' => 'EUPL-1.2',
            'European Union Public Licence (EUPL) v. 1.2' => 'EUPL-1.2',
            'European Union Public Licence (EUPL) v.1.2' => 'EUPL-1.2',
            'European Union Public Licence 1.2 (C) 2022 the authors and Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences' => 'EUPL-1.2',

            // Open Data Commons (3 datasets)
            'Open Data Commons Open Database License (ODbL)' => 'ODbL-1.0',

            // Model data variants
            'Model data are licensed under CC BY 4.0' => 'CC-BY-4.0',

            // Data prefixed variants
            'Data Licence: CC BY 4.0' => 'CC-BY-4.0',
            'Data License: CC BY 4.0' => 'CC-BY-4.0',
            'Data: CC Attribution 4.0 (CC BY 4.0)' => 'CC-BY-4.0',
            'Data: CC BY 4.0' => 'CC-BY-4.0',
            'Datasets: CC BY 4.0' => 'CC-BY-4.0',

            // Code prefixed variants
            'Code: Apache License, version 2.0' => 'Apache-2.0',
            'Code: MIT Licence' => 'MIT',

            // Software prefixed variants
            'Software Licence: Apache License, Version 2.0; Copyright (C) 2022 the authors and their institutions' => 'Apache-2.0',

            // Attribution variants with exclusions
            'Attribution 4.0 International (CC BY 4.0) excluding the conventional logging data owned by HS Orka' => 'CC-BY-4.0',

            // L1B2/RCCM output data
            'L1B2.output data and manual: CC BY 4.0' => 'CC-BY-4.0',
            'RCCM.output data and manual: CC BY 4.0' => 'CC-BY-4.0',

            // MISR public domain
            'MISR input data: public domain (CC0)' => 'CC0-1.0',
        ];

        // Check direct mapping first
        if (isset($mappings[$licenseName])) {
            return $mappings[$licenseName];
        }

        // Pattern matching for licenses with copyright statements
        // Apache License with copyright
        if (preg_match('/Apache License,?\s*Version 2\.0[^;]*(;?\s*Copyright)/i', $licenseName)) {
            return 'Apache-2.0';
        }

        // GNU GPL v3 with copyright (most common pattern: 8+ different copyright variants)
        if (preg_match('/GNU General Public License,?\s*Version 3[^;]*(;?\s*Copyright|29 June 2007)/i', $licenseName)) {
            return 'GPL-3.0-only';
        }

        // BSD-3-Clause with copyright
        if (preg_match('/BSD 3-Clause[^;]*(;?\s*Copyright)/i', $licenseName)) {
            return 'BSD-3-Clause';
        }

        // Try pattern matching for CC licenses
        if (preg_match('/CC\s+BY(?:-NC)?(?:-SA)?(?:-ND)?\s*(\d+\.\d+)?/i', $licenseName, $matches)) {
            $version = $matches[1] ?? '4.0';
            $type = '';

            // Use word boundaries and more specific patterns to avoid false matches
            if (preg_match('/\b(NC|Non-?Commercial|NonCommercial)\b/i', $licenseName)) {
                $type .= '-NC';
            }
            if (preg_match('/\b(SA|Share-?Alike|ShareAlike)\b/i', $licenseName)) {
                $type .= '-SA';
            }
            if (preg_match('/\b(ND|No-?Derivatives?|NoDerivs?)\b/i', $licenseName)) {
                $type .= '-ND';
            }

            return 'CC-BY'.$type.'-'.$version;
        }

        // Try pattern for "Attribution X.0" format
        if (preg_match('/Attribution(?:-NonCommercial)?(?:-ShareAlike)?(?:-NoDerivatives)?\s+(\d+\.\d+)\s+International/i', $licenseName, $matches)) {
            $version = $matches[1];
            $type = '';

            // Use word boundaries to avoid false matches
            if (preg_match('/\bNonCommercial\b/i', $licenseName)) {
                $type .= '-NC';
            }
            if (preg_match('/\bShareAlike\b/i', $licenseName)) {
                $type .= '-SA';
            }
            if (preg_match('/\bNoDerivatives\b/i', $licenseName)) {
                $type .= '-ND';
            }

            return 'CC-BY'.$type.'-'.$version;
        }

        // No mapping found - log for investigation
        Log::warning('Could not map license from old database', [
            'license_name' => $licenseName,
        ]);

        return null;
    }

    /**
     * Load and transform authors from old dataset.
     *
     * @return list<array<string, mixed>>
     */
    private function loadAuthors(int $id): array
    {
        // Load authors from resourceagent table with role 'Creator'
        $authors = DB::connection(self::DATASET_CONNECTION)
            ->select('
                SELECT ra.*, r.role
                FROM resourceagent ra
                INNER JOIN role r ON ra.resource_id = r.resourceagent_resource_id 
                    AND ra.order = r.resourceagent_order
                WHERE ra.resource_id = ? 
                    AND r.role = ?
                ORDER BY ra.order ASC
            ', [$id, 'Creator']);

        $result = [];

        foreach ($authors as $index => $author) {
            $data = [
                'type' => 'person',
                'position' => $index,
            ];

            // Parse name using NameParser to handle both storage formats:
            // 1. Separated: firstname/lastname in separate fields
            // 2. Combined: "Lastname, Firstname" in name field
            $parsedName = NameParser::parsePersonName(
                $author->name,
                $author->firstname,
                $author->lastname
            );

            if (! empty($parsedName['firstName'])) {
                $data['firstName'] = $parsedName['firstName'];
            }

            if (! empty($parsedName['lastName'])) {
                $data['lastName'] = $parsedName['lastName'];
            }

            // Check if author has ORCID (stored in identifier column with identifiertype = 'ORCID')
            if (! empty($author->identifier) && $author->identifiertype === 'ORCID') {
                $data['orcid'] = $author->identifier;
            }

            // Check if this is a contact person
            // Method 1: Check if this specific order has pointOfContact role
            $isContactSameEntry = DB::connection(self::DATASET_CONNECTION)
                ->table('role')
                ->where('resourceagent_resource_id', $id)
                ->where('resourceagent_order', $author->order)
                ->where('role', 'pointOfContact')
                ->exists();

            // Method 2: Check if there's another entry with same name and pointOfContact role
            $isContactOtherEntry = DB::connection(self::DATASET_CONNECTION)
                ->select('
                    SELECT 1
                    FROM resourceagent ra
                    INNER JOIN role r ON ra.resource_id = r.resourceagent_resource_id 
                        AND ra.order = r.resourceagent_order
                    WHERE ra.resource_id = ? 
                        AND r.role = ?
                        AND LOWER(TRIM(ra.name)) = ?
                    LIMIT 1
                ', [$id, 'pointOfContact', strtolower(trim($author->name))]);

            $data['isContact'] = $isContactSameEntry || ! empty($isContactOtherEntry);

            if ($data['isContact']) {
                // Try to get email/website from the current entry or from the pointOfContact entry
                $contactInfo = $author;
                
                // If this entry doesn't have email/website, try to get from pointOfContact entry
                if ((empty($author->email) || empty($author->website)) && ! $isContactSameEntry) {
                    $pointOfContactEntry = DB::connection(self::DATASET_CONNECTION)
                        ->table('resourceagent as ra')
                        ->join('role as r', function ($join) {
                            $join->on('ra.resource_id', '=', 'r.resourceagent_resource_id')
                                ->on('ra.order', '=', 'r.resourceagent_order');
                        })
                        ->where('ra.resource_id', $id)
                        ->where('r.role', 'pointOfContact')
                        ->where(DB::raw('LOWER(TRIM(ra.name))'), strtolower(trim($author->name)))
                        ->select('ra.email', 'ra.website')
                        ->first();

                    if ($pointOfContactEntry) {
                        $contactInfo = $pointOfContactEntry;
                    }
                }

                if (! empty($contactInfo->email)) {
                    $data['email'] = $contactInfo->email;
                }

                if (! empty($contactInfo->website)) {
                    $data['website'] = $contactInfo->website;
                }
            }

            // Load affiliations for this author
            $data['affiliations'] = $this->loadResourceAgentAffiliations($author->resource_id, $author->order);

            $result[] = $data;
        }

        return $result;
    }

    /**
     * Load affiliations for a resourceagent (author or contributor).
     *
     * @param  int  $resourceId  Resource ID
     * @param  int  $agentOrder  Resource agent order
     * @return list<array{value: string, rorId: string|null}>
     */
    private function loadResourceAgentAffiliations(int $resourceId, int $agentOrder): array
    {
        $affiliations = DB::connection(self::DATASET_CONNECTION)
            ->table('affiliation')
            ->where('resourceagent_resource_id', $resourceId)
            ->where('resourceagent_order', $agentOrder)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->get();

        $result = [];

        foreach ($affiliations as $affiliation) {
            $result[] = [
                'value' => $affiliation->name,
                'rorId' => $affiliation->identifier && $affiliation->identifiertype === 'ROR' ? $affiliation->identifier : null,
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
        // Load contributors from resourceagent table with any role except 'Creator'
        $contributors = DB::connection(self::DATASET_CONNECTION)
            ->select('
                SELECT ra.*, GROUP_CONCAT(r.role) as roles
                FROM resourceagent ra
                INNER JOIN role r ON ra.resource_id = r.resourceagent_resource_id 
                    AND ra.order = r.resourceagent_order
                WHERE ra.resource_id = ? 
                    AND r.role != ?
                GROUP BY ra.resource_id, ra.order
                ORDER BY ra.order ASC
            ', [$id, 'Creator']);

        // Get list of Author names (from Creator role entries) to detect duplicates
        // Authors who also have pointOfContact role (in separate entries) should NOT be loaded as Contributors
        $authorNames = DB::connection(self::DATASET_CONNECTION)
            ->select('
                SELECT DISTINCT ra.name, ra.firstname, ra.lastname
                FROM resourceagent ra
                INNER JOIN role r ON ra.resource_id = r.resourceagent_resource_id 
                    AND ra.order = r.resourceagent_order
                WHERE ra.resource_id = ? 
                    AND r.role = ?
            ', [$id, 'Creator']);

        // Build a set of normalized author names for comparison
        $authorNameSet = [];
        foreach ($authorNames as $author) {
            // Normalize name for comparison (trim and lowercase)
            $normalizedName = strtolower(trim($author->name));
            $authorNameSet[$normalizedName] = true;
        }

        $result = [];
        $contributorPosition = 0;

        foreach ($contributors as $contributor) {
            // Skip this contributor if they have the same name as an Author (likely duplicate with pointOfContact role)
            $normalizedContributorName = strtolower(trim($contributor->name));
            if (isset($authorNameSet[$normalizedContributorName])) {
                continue;
            }
            // Parse name to handle both storage formats:
            // 1. Separated: firstname/lastname in separate fields
            // 2. Combined: "Lastname, Firstname" in name field
            $parsedName = NameParser::parsePersonName(
                $contributor->name,
                $contributor->firstname,
                $contributor->lastname
            );

            // Determine if this is a person or institution based on parsed result
            $type = NameParser::isPerson($parsedName) ? 'person' : 'institution';

            $data = [
                'type' => $type,
                'position' => $contributorPosition,
            ];

            $contributorPosition++;

            if ($type === 'person') {
                if (! empty($parsedName['firstName'])) {
                    $data['firstName'] = $parsedName['firstName'];
                }

                if (! empty($parsedName['lastName'])) {
                    $data['lastName'] = $parsedName['lastName'];
                }

                // Check if contributor has ORCID (stored in identifier column with identifiertype = 'ORCID')
                if (! empty($contributor->identifier) && $contributor->identifiertype === 'ORCID') {
                    $data['orcid'] = $contributor->identifier;
                }
            } else {
                // Institution
                if (! empty($contributor->name)) {
                    $data['institutionName'] = $contributor->name;
                }
            }

            // Parse and map roles
            $rolesList = ! empty($contributor->roles) ? explode(',', $contributor->roles) : [];
            $data['roles'] = array_map(function ($role) {
                return $this->mapOldRoleToNew(trim($role));
            }, $rolesList);

            // Load affiliations
            $data['affiliations'] = $this->loadResourceAgentAffiliations($contributor->resource_id, $contributor->order);

            $result[] = $data;
        }

        return $result;
    }

    /**
     * Map old database role names to new database role slugs.
     */
    private function mapOldRoleToNew(string $oldRole): string
    {
        // Use the role mapping from OldDataset model
        $mapping = [
            'Creator' => 'author',
            'pointOfContact' => 'contact-person',
            'ContactPerson' => 'contact-person',
            'DataCollector' => 'data-collector',
            'DataCurator' => 'data-curator',
            'DataManager' => 'data-manager',
            'Editor' => 'editor',
            'Producer' => 'producer',
            'ProjectLeader' => 'project-leader',
            'ProjectManager' => 'project-manager',
            'ProjectMember' => 'project-member',
            'RelatedPerson' => 'related-person',
            'Researcher' => 'researcher',
            'RightsHolder' => 'rights-holder',
            'Supervisor' => 'supervisor',
            'Translator' => 'translator',
            'WorkPackageLeader' => 'work-package-leader',
            'Distributor' => 'distributor',
            'HostingInstitution' => 'hosting-institution',
            'RegistrationAgency' => 'registration-agency',
            'RegistrationAuthority' => 'registration-authority',
            'ResearchGroup' => 'research-group',
            'Sponsor' => 'sponsor',
        ];

        return $mapping[$oldRole] ?? strtolower($oldRole);
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
