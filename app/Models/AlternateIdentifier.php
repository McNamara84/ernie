<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a DataCite alternateIdentifier element.
 *
 * Used primarily for IGSN resources to store:
 * - 'name' field with type "Local accession number"
 * - 'sample_other_names' field with type "Local sample name"
 *
 * @see https://github.com/McNamara84/ernie/issues/465
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/alternateidentifier/
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property string $type
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 */
class AlternateIdentifier extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'value',
        'type',
        'position',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Get the resource that owns this alternate identifier.
     *
     * @return BelongsTo<Resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
