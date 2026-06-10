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
 * One DataCite rights statement attached to a resource.
 *
 * Historically `resource_rights` was only a pivot between resources and the
 * shared rights catalog. SPDX enrichment needs a more expressive model:
 *
 * - `rights_id` points to the trusted shared catalog when a statement is known.
 * - the raw columns keep exactly what came from DataCite/XML/JSON imports.
 * - assistant acceptance only changes this row, so other resources using the
 *   same catalog right are not changed accidentally.
 *
 * @property int $id
 * @property int $resource_id
 * @property int|null $rights_id
 * @property string|null $rights_text
 * @property string|null $rights_uri
 * @property string|null $rights_identifier
 * @property string|null $rights_identifier_scheme
 * @property string|null $scheme_uri
 * @property string|null $language
 * @property string|null $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read Right|null $right
 */
#[Fillable([
    'resource_id',
    'rights_id',
    'rights_text',
    'rights_uri',
    'rights_identifier',
    'rights_identifier_scheme',
    'scheme_uri',
    'language',
    'source',
])]
class ResourceRight extends Model
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

    /** @return BelongsTo<Right, static> */
    public function right(): BelongsTo
    {
        /** @var BelongsTo<Right, static> $relation */
        $relation = $this->belongsTo(Right::class, 'rights_id');

        return $relation;
    }

    public function isResolved(): bool
    {
        return $this->rights_id !== null;
    }
}
