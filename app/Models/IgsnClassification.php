<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IGSN Classification Model
 *
 * Stores rock/sample classifications for IGSN resources.
 * Examples: "Igneous", "Metamorphic", "Sedimentary"
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 */
#[Fillable(['resource_id', 'value', 'position'])]
#[Table('igsn_classifications')]
class IgsnClassification extends Model
{

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Get the resource that owns this classification.
     *
     * @return BelongsTo<Resource, static>
     */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
