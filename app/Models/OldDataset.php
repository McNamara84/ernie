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
     * Get paginated resources with their titles, ordered by created_at descending.
     *
     * @param int $page
     * @param int $perPage
     * @return LengthAwarePaginator<int, OldDataset>
     */
    public static function getPaginatedOrderedByCreatedDate($page = 1, $perPage = 50): LengthAwarePaginator
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
                'resource.version',
                'resource.language',
                'title.title'
            ])
            ->leftJoin('title', 'resource.id', '=', 'title.resource_id')
            ->orderBy('resource.created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
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
                    return [
                        'value' => $affiliation->name,
                        'rorId' => $affiliation->identifier ?? null,
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

        $authors = [];

        foreach ($resourceAgents as $agent) {
            // Get roles for this resourceagent from prefetched data
            $roles = $allRoles[$agent->order] ?? [];

            // Check if this author is a contact person (ContactPerson or pointOfContact)
            $isContact = in_array('ContactPerson', $roles) || in_array('pointOfContact', $roles);

            // Try to find contact information using fuzzy name matching
            $email = null;
            $website = null;
            
            // Try to find matching contact info
            foreach ($allContactInfo as $contactInfo) {
                $matched = false;
                
                // Strategy 1: Normalized name match (if agent has a name)
                if ($agent->name && $contactInfo->name) {
                    $normalizedAgentName = $this->normalizeName($agent->name);
                    $normalizedContactName = $this->normalizeName($contactInfo->name);
                    
                    if ($normalizedAgentName === $normalizedContactName) {
                        $matched = true;
                    }
                }
                
                // Strategy 2: Match by firstname + lastname if available
                // This is checked regardless of whether $agent->name is set
                if (!$matched && $agent->firstname && $agent->lastname && $contactInfo->firstname && $contactInfo->lastname) {
                    $agentFullName = $this->normalizeName($agent->firstname . ' ' . $agent->lastname);
                    $contactFullName = $this->normalizeName($contactInfo->firstname . ' ' . $contactInfo->lastname);
                    
                    if ($agentFullName === $contactFullName) {
                        $matched = true;
                    }
                }
                
                // Strategy 3: Check if normalized names contain each other (partial match)
                // This handles cases like "Läuchli, Charlotte" vs "Läuchli Charlotte"
                if (!$matched && $agent->name && $contactInfo->name) {
                    $normalizedAgentName = $this->normalizeName($agent->name);
                    $normalizedContactName = $this->normalizeName($contactInfo->name);
                    
                    if ($normalizedAgentName && $normalizedContactName) {
                        // Split into words and sort for comparison
                        $agentWords = explode(' ', $normalizedAgentName);
                        $contactWords = explode(' ', $normalizedContactName);
                        sort($agentWords);
                        sort($contactWords);
                        
                        if ($agentWords === $contactWords) {
                            $matched = true;
                        }
                    }
                }
                
                if ($matched) {
                    $email = !empty($contactInfo->email) ? $contactInfo->email : null;
                    $website = !empty($contactInfo->website) ? $contactInfo->website : null;
                    break;
                }
            }
            
            // If we found contact info for this person, mark them as contact
            if ($email || $website) {
                $isContact = true;
            }

            // Get affiliations for this resourceagent from prefetched data
            $affiliations = $allAffiliations[$agent->order] ?? [];

            $authors[] = [
                'givenName' => $agent->firstname,
                'familyName' => $agent->lastname,
                'name' => $agent->name ?? '',
                'affiliations' => $affiliations,
                'roles' => $roles,
                'isContact' => $isContact,
                'email' => $email,
                'website' => $website,
                'orcid' => (!empty($agent->identifier) && strtoupper($agent->identifiertype ?? '') === 'ORCID') ? $agent->identifier : null,
                'orcidType' => $agent->identifiertype ?? null,
            ];
        }

        return $authors;
    }
}