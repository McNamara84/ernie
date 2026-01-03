<?php

namespace App\Models;

use App\Services\SlugGeneratorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $resource_id
 * @property string|null $doi_prefix DOI for URL generation (e.g., "10.5880/igets.bu.l1.001"), NULL for drafts
 * @property string $slug URL-friendly title slug
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
 * @property-read string $public_url
 * @property-read string|null $preview_url
 * @property-read string $status 'published' or 'draft'
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

            // Capture DOI prefix from resource if not set
            if ($landingPage->doi_prefix === null && ! $landingPage->isDirty('doi_prefix')) {
                $landingPage->doi_prefix = $landingPage->getDOIPrefixFromResource();
            }
        });
    }

    /**
     * Generate a URL-friendly slug from the associated resource's main title.
     */
    public function generateSlugFromResource(): string
    {
        $resource = $this->resource ?? Resource::find($this->resource_id);

        if (! $resource) {
            return 'dataset-'.$this->resource_id;
        }

        // Load titles if not already loaded
        if (! $resource->relationLoaded('titles')) {
            $resource->load('titles.titleType');
        }

        // Find main title (title_type_id is NULL or titleType slug is 'main-title')
        $mainTitle = $resource->titles
            ->first(fn (Title $title) => $title->isMainTitle());

        $titleValue = $mainTitle !== null ? $mainTitle->value : 'dataset-'.$resource->id;

        /** @var SlugGeneratorService $slugGenerator */
        $slugGenerator = app(SlugGeneratorService::class);

        return $slugGenerator->generateFromTitle($titleValue);
    }

    /**
     * Get DOI prefix from associated resource.
     * Returns null if resource has no DOI.
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
        if ($this->doi_prefix !== null) {
            // URL with DOI: /{doi}/{slug}
            return url("/{$this->doi_prefix}/{$this->slug}");
        }

        // Draft URL without DOI: /draft-{id}/{slug}
        return url("/draft-{$this->resource_id}/{$this->slug}");
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

        if ($this->doi_prefix !== null) {
            return url("/{$this->doi_prefix}/{$this->slug}?preview={$this->preview_token}");
        }

        return url("/draft-{$this->resource_id}/{$this->slug}?preview={$this->preview_token}");
    }

    /**
     * Get the contact form URL for the landing page.
     *
     * Format: /{DOI}/{SLUG}/contact or /draft-{ID}/{SLUG}/contact
     */
    public function getContactUrlAttribute(): string
    {
        return $this->public_url.'/contact';
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
