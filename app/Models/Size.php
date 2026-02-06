<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Size Model (DataCite #13)
 *
 * Stores size information for a Resource.
 * The `value` field holds the combined DataCite export string (e.g., "0.9 Drilled Length [m]").
 * For IGSN data, the structured columns `numeric_value`, `unit`, and `type` store the
 * decomposed parts for 3NF-compliant storage and structured queries.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property string|null $numeric_value
 * @property string|null $unit
 * @property string|null $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/size/
 */
class Size extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'value',
        'numeric_value',
        'unit',
        'type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numeric_value' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
