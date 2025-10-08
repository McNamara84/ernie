<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @property array<string>|null $licenses
 */
class OldDataset extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'metaworks';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'resource';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * Map old database role names to new database role slugs.
     * This is needed because the old database uses different role names.
     *
     * @var array<string, string>
     */
    private const ROLE_MAPPING = [
        // Author roles
        'Creator' => 'author',
        
        // Contributor Person roles
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
        
        // Contributor Institution roles
        'Distributor' => 'distributor',
        'HostingInstitution' => 'hosting-institution',
        'RegistrationAgency' => 'registration-agency',
        'RegistrationAuthority' => 'registration-authority',
        'ResearchGroup' => 'research-group',
        'Sponsor' => 'sponsor',
        
        // Common fallback
        'Other' => 'other',
    ];

    /**
     * Map an old role name to a new role slug.
     * Returns 'other' if no mapping is found.
     *
     * @param string $oldRole
     * @return string
     */
    private function mapRole(string $oldRole): string
    {
        return self::ROLE_MAPPING[$oldRole] ?? 'other';
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'publicationyear' => 'integer',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'publicstatus',
        'identifier',
        'publisher',
        'publicationyear',
        'keywords',
        'version',
        'language',
        'identifiertype',
        'resourcetype',
        'resourcetypegeneral',
        'publicid',
        'progress',
        'curator',
    ];

    /**
     * Get all resources with their titles, ordered by created_at descending.
     *
     * @return Collection<int, OldDataset>
     */
    public static function getAllOrderedByCreatedDate(): Collection
    {
        return self::select([
                'resource.id',
                'resource.identifier',
                'resource.resourcetypegeneral',
                'resource.curator',
                'resource.created_at',
                'resource.updated_at',
                'resource.publicstatus',
                'resource.publisher',
                'resource.publicationyear',
                'title.title'
            ])
            ->leftJoin('title', 'resource.id', '=', 'title.resource_id')
            ->orderBy('resource.created_at', 'desc')
            ->get();
    }

    /**
     * Get paginated resources with their titles, ordered by the provided column and direction.
     *
     * @param int $page
     * @param int $perPage
     * @param string $sortKey
     * @param string $sortDirection
     * @return LengthAwarePaginator<int, OldDataset>
     */
    public static function getPaginatedOrdered(
        int $page = 1,
        int $perPage = 50,
        string $sortKey = 'updated_at',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        $sortColumn = match ($sortKey) {
            'id' => 'resource.id',
            'created_at' => 'resource.created_at',
            default => 'resource.updated_at',
        };

        $query = self::select([
                'resource.id',
                'resource.identifier',
                'resource.resourcetypegeneral',
                'resource.curator',
                'resource.created_at',
                'resource.updated_at',
                'resource.publicstatus',
                'resource.publisher',
                'resource.publicationyear',
                'resource.version',
                'resource.language',
                'title.title'
            ])
            ->leftJoin('title', 'resource.id', '=', 'title.resource_id')
            ->orderBy($sortColumn, $direction);

        if ($sortColumn !== 'resource.id') {
            $query->orderBy('resource.id', 'asc');
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get licenses for this resource from the license table.
     *
     * @return array<string>
     */
    public function getLicenses(): array
    {
        $licenses = \Illuminate\Support\Facades\DB::connection($this->connection)
            ->table('license')
            ->where('resource_id', $this->id)
            ->pluck('name')
            ->toArray();

        return array_values(array_unique($licenses));
    }

    /**
     * Normalize a name for fuzzy matching by removing punctuation and extra whitespace.
     * Converts names like "Läuchli, Charlotte" to "lauchli charlotte" for comparison.
     * Removes diacritics, punctuation (commas, periods, hyphens), and normalizes whitespace.
     *
     * @param string|null $name The name to normalize
     * @return string The normalized name
     */
    private function normalizeName(?string $name): string
    {
        if (!$name) {
            return '';
        }
        
        // Convert to lowercase
        $normalized = mb_strtolower($name, 'UTF-8');
        
        // Transliterate to ASCII (e.g., ä -> a, ü -> u)
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
        
        // Remove common punctuation (commas, periods, hyphens)
        $normalized = str_replace([',', '.', '-'], '', $normalized);
        
        // Replace multiple whitespace with single space
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        
        // Trim
        return trim($normalized);
    }

    /**
     * Get authors/creators for this resource from the resourceagent table.
     * Returns an array of authors with their roles and affiliations.
     * Only includes resourceagents that have the "Creator" role.
     *
     * @return array<int, array{givenName: string|null, familyName: string|null, name: string, affiliations: array<int, array{value: string, rorId: string|null}>, roles: array<string>, isContact: bool, email: string|null, website: string|null, orcid: string|null, orcidType: string|null}>
     */
    public function getAuthors(): array
    {
        $db = \Illuminate\Support\Facades\DB::connection($this->connection);
        
        // Get all resourceagents for this resource that have the "Creator" role
        $resourceAgents = $db->table('resourceagent')
            ->join('role', function ($join) {
                $join->on('resourceagent.resource_id', '=', 'role.resourceagent_resource_id')
                     ->on('resourceagent.order', '=', 'role.resourceagent_order');
            })
            ->where('resourceagent.resource_id', $this->id)
            ->where('role.role', 'Creator')
            ->select('resourceagent.*')
            ->distinct()
            ->orderBy('resourceagent.order')
            ->get();

        // Prefetch all roles for this resource to avoid N+1 queries
        $allRoles = $db->table('role')
            ->where('resourceagent_resource_id', $this->id)
            ->get()
            ->groupBy('resourceagent_order')
            ->map(function ($roles) {
                return $roles->pluck('role')->toArray();
            })
            ->toArray();

        // Prefetch all affiliations for this resource to avoid N+1 queries
        $allAffiliations = $db->table('affiliation')
            ->where('resourceagent_resource_id', $this->id)
            ->orderBy('resourceagent_order')
            ->orderBy('order')
            ->get()
            ->groupBy('resourceagent_order')
            ->map(function ($affiliations) {
                return $affiliations->map(function ($affiliation) {
                    $identifier = $affiliation->identifier ?? null;

                    // Normalize ROR identifier to full URL format
                    if ($identifier && !empty(trim($identifier))) {
                        // If it's already a full URL, keep it
                        if (!str_starts_with($identifier, 'http')) {
                            // Convert short ROR ID to full URL
                            $identifier = 'https://ror.org/' . ltrim($identifier, '/');
                        }
                    } else {
                        $identifier = null;
                    }

                    return [
                        'value' => $affiliation->name,
                        'rorId' => $identifier,
                    ];
                })->toArray();
            })
            ->toArray();

        // Get all contact information for this resource
        // We'll use fuzzy name matching to find the right contact info
        $allContactInfo = $db->table('contactinfo')
            ->join('resourceagent', function ($join) {
                $join->on('contactinfo.resourceagent_resource_id', '=', 'resourceagent.resource_id')
                     ->on('contactinfo.resourceagent_order', '=', 'resourceagent.order');
            })
            ->where('resourceagent.resource_id', $this->id)
            ->select('resourceagent.name', 'resourceagent.firstname', 'resourceagent.lastname', 'contactinfo.email', 'contactinfo.website')
            ->get();

        // Precompute normalized values for contact info to avoid repeated normalizeName calls
        $normalizedContactInfo = $allContactInfo->map(function ($contactInfo) {
            /** @var array{email: string|null, website: string|null, normalizedName: string|null, normalizedFullName: string|null, normalizedWords: array<int, string>|null} $normalized */
            $normalized = [
                'email' => $contactInfo->email ?? null,
                'website' => $contactInfo->website ?? null,
                'normalizedName' => null,
                'normalizedFullName' => null,
                'normalizedWords' => null,
            ];

            if ($contactInfo->name) {
                $normalized['normalizedName'] = $this->normalizeName($contactInfo->name);
                if ($normalized['normalizedName']) {
                    $words = explode(' ', $normalized['normalizedName']);
                    sort($words);
                    $normalized['normalizedWords'] = $words;
                }
            }

            if ($contactInfo->firstname && $contactInfo->lastname) {
                $normalized['normalizedFullName'] = $this->normalizeName($contactInfo->firstname . ' ' . $contactInfo->lastname);
            }

            return $normalized;
        });

        $authors = [];

        foreach ($resourceAgents as $agent) {
            // Get roles for this resourceagent from prefetched data
            $roles = $allRoles[$agent->order] ?? [];

            // Check if this author is a contact person (ContactPerson or pointOfContact)
            $isContact = in_array('ContactPerson', $roles) || in_array('pointOfContact', $roles);

            // Try to find contact information using fuzzy name matching
            $email = null;
            $website = null;
            
            // Precompute agent's normalized values
            $agentNormalizedName = $agent->name ? $this->normalizeName($agent->name) : null;
            $agentNormalizedFullName = ($agent->firstname && $agent->lastname) 
                ? $this->normalizeName($agent->firstname . ' ' . $agent->lastname) 
                : null;
            $agentNormalizedWords = null;
            if ($agentNormalizedName) {
                $words = explode(' ', $agentNormalizedName);
                sort($words);
                $agentNormalizedWords = $words;
            }
            
            // Try to find matching contact info
            foreach ($normalizedContactInfo as $contactInfo) {
                $matched = false;
                
                // Strategy 1: Normalized name match (if agent has a name)
                if ($agentNormalizedName && $contactInfo['normalizedName']) {
                    if ($agentNormalizedName === $contactInfo['normalizedName']) {
                        $matched = true;
                    }
                }
                
                // Strategy 2: Match by firstname + lastname if available
                // This is checked regardless of whether $agent->name is set
                if (!$matched && $agentNormalizedFullName && $contactInfo['normalizedFullName']) {
                    if ($agentNormalizedFullName === $contactInfo['normalizedFullName']) {
                        $matched = true;
                    }
                }
                
                // Strategy 3: Check if normalized names contain each other (partial match)
                // This handles cases like "Läuchli, Charlotte" vs "Läuchli Charlotte"
                if (!$matched && $agentNormalizedWords && $contactInfo['normalizedWords']) {
                    if ($agentNormalizedWords === $contactInfo['normalizedWords']) {
                        $matched = true;
                    }
                }
                
                if ($matched) {
                    $email = !empty($contactInfo['email']) ? $contactInfo['email'] : null;
                    $website = !empty($contactInfo['website']) ? $contactInfo['website'] : null;
                    break;
                }
            }
            
            // If we found contact info for this person, mark them as contact
            if ($email || $website) {
                $isContact = true;
            }

            // Get affiliations for this resourceagent from prefetched data
            $affiliations = $allAffiliations[$agent->order] ?? [];

            // Map old role names to new role slugs
            $mappedRoles = array_map(fn($role) => $this->mapRole($role), $roles);

            $authors[] = [
                'givenName' => $agent->firstname,
                'familyName' => $agent->lastname,
                'name' => $agent->name ?? '',
                'affiliations' => $affiliations,
                'roles' => $mappedRoles,
                'isContact' => $isContact,
                'email' => $email,
                'website' => $website,
                'orcid' => (!empty($agent->identifier) && strtoupper($agent->identifiertype ?? '') === 'ORCID') ? $agent->identifier : null,
                'orcidType' => $agent->identifiertype ?? null,
            ];
        }

        return $authors;
    }

    /**
     * Get contributors for this resource from the resourceagent table.
     * Returns an array of contributors with their roles and affiliations.
     * Only includes resourceagents that do NOT have the "Creator" role.
     *
     * @return array<int, array{type: string, givenName: string|null, familyName: string|null, name: string|null, institutionName: string|null, affiliations: array<int, array{value: string, rorId: string|null}>, roles: array<string>, orcid: string|null, orcidType: string|null}>
     */
    public function getContributors(): array
    {
        $db = \Illuminate\Support\Facades\DB::connection($this->connection);
        
        // Get all resourceagents for this resource
        $allResourceAgents = $db->table('resourceagent')
            ->where('resource_id', $this->id)
            ->orderBy('order')
            ->get();

        // Prefetch all roles for this resource to avoid N+1 queries
        $allRoles = $db->table('role')
            ->where('resourceagent_resource_id', $this->id)
            ->get()
            ->groupBy('resourceagent_order')
            ->map(function ($roles) {
                return $roles->pluck('role')->toArray();
            })
            ->toArray();

        // Filter out resourceagents that have the "Creator" role
        $contributorAgents = $allResourceAgents->filter(function ($agent) use ($allRoles) {
            $roles = $allRoles[$agent->order] ?? [];
            return !in_array('Creator', $roles);
        });

        // Prefetch all affiliations for this resource to avoid N+1 queries
        $allAffiliations = $db->table('affiliation')
            ->where('resourceagent_resource_id', $this->id)
            ->orderBy('resourceagent_order')
            ->orderBy('order')
            ->get()
            ->groupBy('resourceagent_order')
            ->map(function ($affiliations) {
                return $affiliations->map(function ($affiliation) {
                    $identifier = $affiliation->identifier ?? null;

                    // Normalize ROR identifier to full URL format
                    if ($identifier && !empty(trim($identifier))) {
                        // If it's already a full URL, keep it
                        if (!str_starts_with($identifier, 'http')) {
                            // Convert short ROR ID to full URL
                            $identifier = 'https://ror.org/' . ltrim($identifier, '/');
                        }
                    } else {
                        $identifier = null;
                    }

                    return [
                        'value' => $affiliation->name,
                        'rorId' => $identifier,
                    ];
                })->toArray();
            })
            ->toArray();

        $contributors = [];

        foreach ($contributorAgents as $agent) {
            // Get roles for this resourceagent from prefetched data
            $roles = $allRoles[$agent->order] ?? [];

            // Get affiliations for this resourceagent from prefetched data
            $affiliations = $allAffiliations[$agent->order] ?? [];

            // Determine if this is a person or institution
            // Strategy: Use roles as primary indicator, then fallback to name analysis
            
            // 1. Roles that ONLY apply to institutions (strongest indicator)
            $institutionOnlyRoles = ['HostingInstitution', 'Distributor', 'ResearchGroup', 'Sponsor'];
            $hasInstitutionOnlyRole = !empty(array_intersect($roles, $institutionOnlyRoles));
            
            // 2. Name format indicators
            $hasCommaSeparatedName = !empty($agent->name) && str_contains($agent->name, ',');
            
            // Decision logic (in priority order):
            // 1. If has HostingInstitution/Distributor/ResearchGroup/Sponsor → ALWAYS Institution
            // 2. If name contains comma → Person (format: "Lastname, Firstname")
            // 3. If has firstname OR lastname → Person
            // 4. Default → Institution
            
            if ($hasInstitutionOnlyRole) {
                $isPerson = false;
            } elseif ($hasCommaSeparatedName) {
                $isPerson = true;
            } elseif (!empty($agent->firstname) || !empty($agent->lastname)) {
                $isPerson = true;
            } else {
                $isPerson = false;
            }
            
            $isInstitution = !$isPerson;
            
            // For institutions: use the name field if available, otherwise use the first affiliation
            $institutionName = null;
            if ($isInstitution) {
                if (!empty($agent->name)) {
                    $institutionName = $agent->name;
                } elseif (!empty($affiliations)) {
                    // Use the first affiliation's value as institution name
                    $firstAffiliation = reset($affiliations);
                    if ($firstAffiliation && isset($firstAffiliation['value']) && !empty($firstAffiliation['value'])) {
                        $institutionName = $firstAffiliation['value'];
                    }
                }
            }
            
            // Map old role names to new role slugs
            $mappedRoles = array_map(fn($role) => $this->mapRole($role), $roles);
            
            $contributors[] = [
                'type' => $isPerson ? 'person' : 'institution',
                'givenName' => $isPerson ? $agent->firstname : null,
                'familyName' => $isPerson ? $agent->lastname : null,
                'name' => $isPerson ? ($agent->name ?? '') : null,
                'institutionName' => $institutionName,
                'affiliations' => $affiliations,
                'roles' => $mappedRoles,
                'orcid' => (!empty($agent->identifier) && strtoupper($agent->identifiertype ?? '') === 'ORCID') ? $agent->identifier : null,
                'orcidType' => $agent->identifiertype ?? null,
            ];
        }

        return $contributors;
    }

    /**
     * Get descriptions for this resource from the description table.
     * Returns an array of descriptions with their types.
     *
     * @return array<int, array{type: string, description: string}>
     */
    public function getDescriptions(): array
    {
        if (!$this->exists) {
            return [];
        }

        $db = \Illuminate\Support\Facades\DB::connection($this->connection);
        
        // Get all descriptions for this resource
        $descriptions = $db->table('description')
            ->where('resource_id', $this->id)
            ->select('descriptiontype', 'description')
            ->get();

        return $descriptions->map(function ($desc) {
            return [
                'type' => $desc->descriptiontype,
                'description' => $desc->description,
            ];
        })->toArray();
    }
}