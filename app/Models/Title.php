<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Title Model (DataCite #3)
 *
 * Stores titles for a Resource with type and language.
 *
 * Note: In DataCite XML, MainTitle has no titleType attribute (it's omitted).
 * In the database, all titles should reference a TitleType record, including MainTitle.
 *
 * Legacy note: The database schema allows title_type_id to be nullable for backwards
 * compatibility. The migration 2026_01_07_100000_make_title_type_id_not_nullable
 * converts all NULL values to MainTitle and makes the column NOT NULL.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property int|null $title_type_id May be null in legacy data (treated as MainTitle)
 * @property string|null $language
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read TitleType|null $titleType May be null in legacy data (treated as MainTitle)
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
     * Main titles are identified by:
     * - Having a NULL title_type_id (legacy data, treated as MainTitle)
     * - Having a TitleType with slug 'MainTitle'
     *
     * In DataCite XML, MainTitle has no titleType attribute, but in the database
     * it's always stored with a reference to the MainTitle TitleType record.
     *
     * Note: This method intentionally does NOT lazy-load the titleType relation
     * to prevent N+1 query problems in loops. If the relation is not loaded and
     * title_type_id is not NULL, this returns false. Callers that need accurate
     * detection must eager load `titleType` before calling this method.
     */
    public function isMainTitle(): bool
    {
        // Legacy data: NULL title_type_id is treated as MainTitle
        if ($this->title_type_id === null) {
            return true;
        }

        // Do NOT lazy-load to prevent N+1 queries - return false if relation not loaded
        if (! $this->relationLoaded('titleType')) {
            return false;
        }

        // Handle case where relation couldn't be loaded (FK points to deleted record)
        if ($this->titleType === null) {
            return false;
        }

        $slug = $this->titleType->slug;
        $normalised = \Illuminate\Support\Str::kebab($slug);

        // 'MainTitle' in kebab-case becomes 'main-title'
        return $normalised === 'main-title';
    }
}
