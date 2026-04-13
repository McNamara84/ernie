<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AssistantDismissed Model
 *
 * Tracks suggestions that were declined by curators, so they won't be
 * re-suggested in future discovery runs. Used by the generic table system
 * for new student-created assistant modules.
 *
 * @property int $id
 * @property string $assistant_id
 * @property string $target_type
 * @property int $target_id
 * @property string $dismissed_value
 * @property int|null $dismissed_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $dismissedByUser
 */
#[Fillable([
    'assistant_id',
    'target_type',
    'target_id',
    'dismissed_value',
    'dismissed_by',
    'reason',
])]
class AssistantDismissed extends Model
{
    protected $table = 'assistant_dismissed';

    /** @return BelongsTo<User, static> */
    public function dismissedByUser(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'dismissed_by');

        return $relation;
    }
}
