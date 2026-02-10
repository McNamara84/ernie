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
 * parts. The export string (e.g., "3 Drilled Length [m]") is built dynamically
 * via the `export_string` accessor.
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
     * Build DataCite export string from numeric_value, type, and unit.
     *
     * When a type is present, the unit is shown in brackets after the type:
     *   "851.88 Total Cored Length [m]", "3 Drilled Length [m]"
     *
     * Without a type, unit is appended directly (backward compatible):
     *   "1.5 GB", "15 pages", "250"
     */
    public function getExportStringAttribute(): string
    {
        $parts = [];

        if ($this->numeric_value !== null) {
            // Format: strip trailing zeros (3.0000 → 3, 0.9000 → 0.9)
            $formatted = rtrim(rtrim($this->numeric_value, '0'), '.');
            // rtrim turns "0.0000" into "" – restore the zero
            $parts[] = $formatted === '' ? '0' : $formatted;
        }

        if ($this->type !== null && $this->type !== '') {
            if ($this->unit !== null && $this->unit !== '') {
                // Type with unit in brackets: "Drilled Length [m]"
                $parts[] = $this->type . ' [' . $this->unit . ']';
            } else {
                // Type only: "meters"
                $parts[] = $this->type;
            }
        } elseif ($this->unit !== null && $this->unit !== '') {
            // Unit only (backward compatible for non-IGSN sizes): "GB"
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
