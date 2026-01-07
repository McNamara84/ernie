<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Description Model (DataCite #17)
 *
 * Stores descriptions for a Resource with type and optional language.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property int $description_type_id
 * @property string|null $language
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read DescriptionType $descriptionType
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/description/
 */
class Description extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'value',
        'description_type_id',
        'language',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<DescriptionType, static> */
    public function descriptionType(): BelongsTo
    {
        /** @var BelongsTo<DescriptionType, static> $relation */
        $relation = $this->belongsTo(DescriptionType::class);

        return $relation;
    }

    /**
     * Check if this is an abstract.
     */
    public function isAbstract(): bool
    {
        return $this->descriptionType->slug === 'Abstract';
    }
}
