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
 * @property string $title
 * @property int|null $title_type_id
 * @property int|null $language_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Resource $resource
 * @property-read TitleType|null $titleType
 * @property-read Language|null $language
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/title/
 */
class Title extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'title',
        'title_type_id',
        'language_id',
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

    /** @return BelongsTo<Language, static> */
    public function language(): BelongsTo
    {
        /** @var BelongsTo<Language, static> $relation */
        $relation = $this->belongsTo(Language::class);

        return $relation;
    }

    /**
     * Check if this is the main title (no type specified).
     */
    public function isMainTitle(): bool
    {
        return $this->title_type_id === null;
    }
}
