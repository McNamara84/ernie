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
 * @property bool $is_published
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read string $public_url
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
        'is_published',
        'published_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'public_url',
    ];

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
