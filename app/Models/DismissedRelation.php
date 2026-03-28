<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DismissedRelation Model
 *
 * Stores relations that curators have explicitly declined,
 * preventing them from being re-suggested in future discovery runs.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $identifier
 * @property int $relation_type_id
 * @property int|null $dismissed_by
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read RelationType $relationType
 * @property-read User|null $dismissedBy
 */
#[Fillable([
    'resource_id',
    'identifier',
    'relation_type_id',
    'dismissed_by',
    'reason',
])]
class DismissedRelation extends Model
{
    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<RelationType, static> */
    public function relationType(): BelongsTo
    {
        /** @var BelongsTo<RelationType, static> $relation */
        $relation = $this->belongsTo(RelationType::class);

        return $relation;
    }

    /** @return BelongsTo<User, static> */
    public function dismissedBy(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'dismissed_by');

        return $relation;
    }
}
