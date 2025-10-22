<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
        'template',
        'ftp_url',
        'status',
        'preview_token',
        'published_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'published_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'view_count' => 'integer',
    ];

    /**
     * Template constants.
     *
     * @var string
     */
    public const TEMPLATE_DEFAULT_GFZ = 'default_gfz';

    /**
     * Available templates.
     *
     * @var array<string, string>
     */
    public const TEMPLATES = [
        self::TEMPLATE_DEFAULT_GFZ => 'Default GFZ Data Services',
        // Future templates can be added here
    ];

    /**
     * Status constants.
     *
     * @var string
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /**
     * Get the resource that owns this landing page.
     *
     * @return BelongsTo<Resource, static>
     */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /**
     * Generate a new preview token (64 characters).
     */
    public function generatePreviewToken(): string
    {
        $token = Str::random(64);
        $this->preview_token = $token;
        $this->save();

        return $token;
    }

    /**
     * Get the public landing page URL.
     */
    public function getPublicUrlAttribute(): string
    {
        return route('landing-page.show', ['resourceId' => $this->resource_id]);
    }

    /**
     * Get the preview URL with token.
     */
    public function getPreviewUrlAttribute(): string
    {
        return $this->public_url.'?preview='.$this->preview_token;
    }

    /**
     * Check if landing page is published.
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Check if landing page is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Publish the landing page.
     */
    public function publish(): void
    {
        $this->update([
            'status' => self::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /**
     * Unpublish the landing page (set to draft).
     */
    public function unpublish(): void
    {
        $this->update([
            'status' => self::STATUS_DRAFT,
        ]);
    }

    /**
     * Increment the view counter.
     */
    public function incrementViewCount(): void
    {
        $this->increment('view_count');
        $this->update(['last_viewed_at' => now()]);
    }

    /**
     * Get all available template options.
     *
     * @return array<array<string, string>>
     */
    public static function getTemplateOptions(): array
    {
        $options = [];
        foreach (self::TEMPLATES as $key => $label) {
            $options[] = [
                'value' => $key,
                'label' => $label,
            ];
        }

        return $options;
    }
}
