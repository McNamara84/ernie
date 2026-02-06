<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Size Model (DataCite #13)
 *
 * Stores size information for a Resource in 3NF.
 * The structured columns `numeric_value`, `unit`, and `type` store the decomposed
 * parts. The export string (e.g., "3 m") is built dynamically via the
 * `export_string` accessor.
 *
 * @property int $id
 * @property int $resource_id
 * @property string|null $numeric_value Decimal value stored as string by Laravel's decimal:4 cast
 * @property string|null $unit

 * @property string|null $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $export_string
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

    /**
     * Build DataCite export string from numeric_value and unit.
     *
     * Examples: "3 m", "87 mm", "15 pages", "250"
     */
    public function getExportStringAttribute(): string
    {
        $parts = [];

        if ($this->numeric_value !== null) {
            // Format: strip trailing zeros (3.0000 → 3, 0.9000 → 0.9)
            $parts[] = rtrim(rtrim($this->numeric_value, '0'), '.');
        }

        if ($this->unit !== null && $this->unit !== '') {
            $parts[] = $this->unit;
        }

        return implode(' ', $parts);
    }

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
