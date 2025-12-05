<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $resource_id
 * @property string $slug
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
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (LandingPage $landingPage): void {
            if (empty($landingPage->preview_token)) {
                $landingPage->preview_token = Str::random(64);
            }
        });
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
     */
    public function getPublicUrlAttribute(): string
    {
        return route('landing-page.show', ['resourceId' => $this->resource_id]);
    }

    /**
     * Get the preview URL for the landing page.
     */
    public function getPreviewUrlAttribute(): ?string
    {
        if (! $this->preview_token) {
            return null;
        }

        return route('landing-page.show', [
            'resourceId' => $this->resource_id,
            'preview' => $this->preview_token,
        ]);
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
}
