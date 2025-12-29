<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Title Model (DataCite #3)
 *
 * Stores titles for a Resource with optional type and language.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property int|null $title_type_id
 * @property string|null $language
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Resource $resource
 * @property-read TitleType|null $titleType
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/title/
 */
class Title extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'value',
        'title_type_id',
        'language',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<TitleType, static> */
    public function titleType(): BelongsTo
    {
        /** @var BelongsTo<TitleType, static> $relation */
        $relation = $this->belongsTo(TitleType::class);

        return $relation;
    }

    /**
     * Check if this is the main title.
     *
     * Main titles are represented as a NULL title_type_id.
     *
     * Note: This method intentionally does NOT lazy-load the titleType relation.
     * If the relation is not loaded and title_type_id is not NULL, this returns false.
     * Callers that need to detect legacy "MainTitle" rows should eager load `titleType`.
     */
    public function isMainTitle(): bool
    {
        if ($this->title_type_id === null) {
            return true;
        }

        if (! $this->relationLoaded('titleType')) {
            return false;
        }

        $slug = $this->titleType?->slug;
        if ($slug === null) {
            return false;
        }

        $normalised = \Illuminate\Support\Str::kebab($slug);

        return $normalised === 'main-title';
    }
}
