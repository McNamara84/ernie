<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DismissedRor Model
 *
 * Stores ROR-ID suggestions that curators have explicitly declined,
 * preventing them from being re-suggested for the same entity.
 *
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $ror_id
 * @property int|null $dismissed_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $dismissedBy
 */
#[Fillable([
    'entity_type',
    'entity_id',
    'ror_id',
    'dismissed_by',
    'reason',
])]
class DismissedRor extends Model
{
    /** @return BelongsTo<User, static> */
    public function dismissedBy(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'dismissed_by');

        return $relation;
    }
}
