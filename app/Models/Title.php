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
 * In the database, all titles must reference a TitleType record, including MainTitle.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property int $title_type_id
 * @property string|null $language
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Resource $resource
 * @property-read TitleType $titleType
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
     * Main titles are identified by having a TitleType with slug 'MainTitle'.
     * In DataCite XML, MainTitle has no titleType attribute, but in the database
     * it's always stored with a reference to the MainTitle TitleType record.
     *
     * Note: This method requires the titleType relation to be loaded for accurate results.
     * Callers should eager load `titleType` when checking multiple titles.
     */
    public function isMainTitle(): bool
    {
        if (! $this->relationLoaded('titleType')) {
            $this->load('titleType');
        }

        $slug = $this->titleType->slug;

        $normalised = \Illuminate\Support\Str::kebab($slug);

        return $normalised === 'maintitle' || $normalised === 'main-title';
    }
}
