<?php

namespace App\Models;

use App\Services\SlugGeneratorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Landing page configuration for a research dataset.
 *
 * @property int $id
 * @property int $resource_id
 * @property string|null $doi_prefix DOI for URL generation (e.g., "10.5880/igets.bu.l1.001"), NULL for drafts
 * @property string $slug URL-friendly title slug (immutable after creation - see note below)
 * @property string $template
 * @property string|null $ftp_url
 * @property bool $is_published
 * @property string|null $preview_token
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int $view_count
 * @property \Illuminate\Support\Carbon|null $last_viewed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read string $public_url Full public URL for the landing page
 * @property-read string|null $preview_url Full preview URL with token
 * @property-read string $contact_url Full contact form URL
 * @property-read string $status 'published' or 'draft'
 *
 * ## Slug Immutability
 *
 * The slug is generated once at creation from the resource's main title and is
 * immutable thereafter. This design decision ensures:
 *
 * 1. **URL Stability**: Published URLs remain valid even if the title is updated
 * 2. **SEO Consistency**: Search engines can index stable URLs
 * 3. **Citation Reliability**: Citations linking to the landing page remain valid
 *
 * If the resource's main title changes significantly after the landing page is
 * created, the slug will no longer reflect the current title. This is intentional
 * to prevent breaking existing links. To update the slug, the landing page must
 * be deleted and recreated (which should only be done if the page hasn't been
 * published or widely shared).
 *
 * ## Performance Considerations
 *
 * The generateSlugFromResource() and getDOIPrefixFromResource() methods are called
 * during the model's boot event. They may trigger additional database queries if
 * the resource relationship is not already loaded. For single creations, this is
 * acceptable (one extra query per creation). For bulk operations, ensure the resource
 * relationship is eager-loaded to avoid N+1 queries:
 *
 * ```php
 * $resources = Resource::with('titles.titleType')->whereIn('id', $ids)->get();
 * foreach ($resources as $resource) {
 *     $landingPage = new LandingPage(['resource_id' => $resource->id]);
 *     $landingPage->setRelation('resource', $resource);
 *     $landingPage->save();
 * }
 * ```
 *
 * ## API Response Structure
 *
 * When serialized (e.g., in JSON API responses), this model includes:
 * - All database columns (id, resource_id, doi_prefix, slug, template, etc.)
 * - Computed accessors: public_url, preview_url, contact_url, status
 *
 * @see LandingPageController::store() for API creation endpoint
 * @see LandingPageController::update() for API update endpoint
 */
class LandingPage extends Model
{
    /** @use HasFactory<\Database\Factories\LandingPageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'doi_prefix',
        'slug',
        'template',
        'ftp_url',
        'is_published',
        'preview_token',
        'published_at',
        'view_count',
        'last_viewed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'view_count' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'public_url',
        'preview_url',
        'contact_url',
        'status',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (LandingPage $landingPage): void {
            // Generate preview token if not set
            if (empty($landingPage->preview_token)) {
                $landingPage->preview_token = Str::random(64);
            }

            // Generate slug from main title if not set (immutable after creation)
            if (empty($landingPage->slug)) {
                $landingPage->slug = $landingPage->generateSlugFromResource();
            }

            // Capture DOI prefix from resource only if not explicitly provided.
            // We use array_key_exists() instead of isset() because:
            // - isset() returns false for null values, so we couldn't distinguish
            //   between "not provided" and "explicitly set to null"
            // - When factory uses withoutDoi(), doi_prefix is explicitly set to null
            //   and should remain null (draft mode), not be overwritten by resource DOI
            // - When creating normally without specifying doi_prefix, we auto-populate
            //   from the resource's DOI
            if (! array_key_exists('doi_prefix', $landingPage->getAttributes())) {
                $landingPage->doi_prefix = $landingPage->getDOIPrefixFromResource();
            }
        });

        // Enforce slug immutability at the model level.
        // The slug is part of the public URL and must never change after creation
        // to ensure citation stability and SEO consistency.
        // See class docblock for rationale on slug immutability.
        static::updating(function (LandingPage $landingPage): void {
            if ($landingPage->isDirty('slug')) {
                throw new \RuntimeException(
                    'Cannot modify landing page slug after creation. ' .
                    'Slugs are immutable to ensure URL stability for citations and SEO. ' .
                    'To change the URL, delete and recreate the landing page.'
                );
            }
        });
    }

    /**
     * Generate a URL-friendly slug from the associated resource's main title.
     *
     * Performance Note: This method may trigger database queries if relationships
     * are not already loaded. For bulk operations, ensure resources are pre-loaded:
     *
     * ```php
     * $resources = Resource::with('titles.titleType')->whereIn('id', $ids)->get();
     * foreach ($resources as $resource) {
     *     $landingPage = new LandingPage(['resource_id' => $resource->id]);
     *     $landingPage->setRelation('resource', $resource);
     *     $landingPage->save();
     * }
     * ```
     *
     * The method explicitly checks if relationships are loaded before calling
     * loadMissing() to make the N+1 potential more obvious in query logs.
     */
    public function generateSlugFromResource(): string
    {
        // Check if resource relationship is already loaded
        $resource = $this->relationLoaded('resource')
            ? $this->resource
            : Resource::find($this->resource_id);

        // If resource not found, throw an exception.
        // A landing page without a valid resource is invalid data - the foreign key
        // constraint on landing_pages.resource_id enforces this at the database level.
        // Throwing here provides a clear error message rather than silently creating
        // orphaned data that would fail on database insert anyway.
        if (! $resource) {
            throw new \InvalidArgumentException(
                "Cannot generate slug: Resource with ID {$this->resource_id} not found. " .
                'A landing page must be associated with a valid resource.'
            );
        }

        // Check for missing eager-loaded relationships.
        // If the resource was pre-loaded (for bulk operations), we require that titles
        // AND titleType are also loaded. This prevents silent N+1 queries and forces
        // callers to eager-load correctly. For single creations where the resource
        // was fetched via Resource::find(), we load the relationships as a convenience.
        $wasPreloaded = $this->relationLoaded('resource');

        if (! $resource->relationLoaded('titles')) {
            if ($wasPreloaded) {
                // Resource was pre-loaded but titles weren't - this is a caller error
                throw new \InvalidArgumentException(
                    'Resource was pre-loaded via setRelation() but titles relationship is missing. ' .
                    'For bulk operations, use: Resource::with(\'titles.titleType\')->...'
                );
            }
            // Single creation - load as convenience
            $resource->load('titles.titleType');
        } elseif ($resource->titles->isNotEmpty() && ! $resource->titles->first()->relationLoaded('titleType')) {
            if ($wasPreloaded) {
                // Titles loaded but titleType isn't - this is a caller error
                throw new \InvalidArgumentException(
                    'Resource was pre-loaded with titles but titleType relationship is missing. ' .
                    'For bulk operations, use: Resource::with(\'titles.titleType\')->...'
                );
            }
            // Single creation - reload with nested relation as convenience
            $resource->load('titles.titleType');
        }

        // Find main title (title_type_id is NULL or titleType slug is 'main-title')
        $mainTitle = $resource->titles
            ->first(fn (Title $title) => $title->isMainTitle());

        // If no main title exists, return a unique fallback slug using resource ID.
        // This ensures uniqueness while maintaining a readable slug format.
        if ($mainTitle === null) {
            return "dataset-{$resource->id}";
        }

        /** @var SlugGeneratorService $slugGenerator */
        $slugGenerator = app(SlugGeneratorService::class);

        return $slugGenerator->generateFromTitle($mainTitle->value);
    }

    /**
     * Get DOI prefix from associated resource.
     * Returns null if resource has no DOI.
     *
     * Performance Note: This method may trigger a database query if the
     * 'resource' relationship is not already loaded. For batch operations,
     * ensure resources are eager-loaded. In typical usage during landing
     * page creation (boot event), this is a single query per creation which
     * is acceptable. If you see N+1 issues in other contexts, use
     * $landingPage->load('resource') or eager load in the query.
     */
    public function getDOIPrefixFromResource(): ?string
    {
        $resource = $this->resource ?? Resource::find($this->resource_id);

        return $resource?->doi;
    }

    /**
     * Get the resource that owns this landing page.
     *
     * @return BelongsTo<\App\Models\Resource, static>
     */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<\App\Models\Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /**
     * Get the public landing page URL.
     *
     * Format: /{DOI}/{SLUG} or /draft-{ID}/{SLUG}
     * Examples:
     * - /10.5880/igets.bu.l1.001/superconducting-gravimeter-data
     * - /draft-42/my-dataset-title
     */
    public function getPublicUrlAttribute(): string
    {
        return url($this->getPublicPath());
    }

    /**
     * Get the relative path portion of the public URL.
     *
     * This method returns the path without the host/scheme, useful for:
     * - Test helpers that need relative paths for Playwright navigation
     * - Internal URL construction where the host is provided separately
     * - Consistency between different environments (local, Docker, CI)
     *
     * Format: /{DOI}/{SLUG} or /draft-{ID}/{SLUG}
     *
     * @see getPublicUrlAttribute() for absolute URL with host
     */
    public function getPublicPath(): string
    {
        if ($this->doi_prefix !== null) {
            return "/{$this->doi_prefix}/{$this->slug}";
        }

        return "/draft-{$this->resource_id}/{$this->slug}";
    }

    /**
     * Get the preview URL for the landing page.
     *
     * Same as public URL but with preview token query parameter.
     */
    public function getPreviewUrlAttribute(): ?string
    {
        if (! $this->preview_token) {
            return null;
        }

        return url($this->getPublicPath() . "?preview={$this->preview_token}");
    }

    /**
     * Get the contact form URL for the landing page.
     *
     * Uses the same URL construction logic as public_url to ensure consistency.
     * If the public URL format changes (e.g., adding query parameters), this
     * method will automatically inherit those changes.
     *
     * Format: /{DOI}/{SLUG}/contact or /draft-{ID}/{SLUG}/contact
     */
    public function getContactUrlAttribute(): string
    {
        return url($this->getPublicPath() . '/contact');
    }

    /**
     * Generate a new preview token.
     */
    public function generatePreviewToken(): string
    {
        $newToken = Str::random(64);
        $this->update([
            'preview_token' => $newToken,
        ]);

        return $newToken;
    }

    /**
     * Increment the view count.
     */
    public function incrementViewCount(): void
    {
        $this->increment('view_count');
        $this->update(['last_viewed_at' => now()]);
    }

    /**
     * Check if landing page is published.
     */
    public function isPublished(): bool
    {
        return $this->is_published;
    }

    /**
     * Check if landing page is draft.
     */
    public function isDraft(): bool
    {
        return ! $this->is_published;
    }

    /**
     * Publish the landing page.
     */
    public function publish(): void
    {
        $this->update([
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * Unpublish the landing page (set to draft).
     */
    public function unpublish(): void
    {
        $this->update([
            'is_published' => false,
            'published_at' => null,
        ]);
    }

    /**
     * Get the status string for API responses.
     *
     * @return string 'published' or 'draft'
     */
    public function getStatusAttribute(): string
    {
        return $this->is_published ? 'published' : 'draft';
    }
}
