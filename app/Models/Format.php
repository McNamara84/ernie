<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Format Model (DataCite #14)
 *
 * Stores format/MIME type information for a Resource.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resource $resource
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/format/
 */
#[Fillable(['resource_id', 'value'])]
class Format extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
