<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $guided_tour_id
 * @property string $status
 * @property string $assignment_source
 * @property int|null $assigned_by
 * @property \Illuminate\Support\Carbon|null $assigned_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $last_triggered_at
 */
#[Fillable(['user_id', 'guided_tour_id', 'status', 'assignment_source', 'assigned_by', 'assigned_at', 'started_at', 'completed_at', 'last_triggered_at'])]
class UserGuidedTourAssignment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const SOURCE_AUTOMATIC = 'automatic';

    public const SOURCE_MANUAL = 'manual';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_triggered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, static>
     */
    public function user(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class);

        return $relation;
    }

    /**
     * @return BelongsTo<GuidedTour, static>
     */
    public function guidedTour(): BelongsTo
    {
        /** @var BelongsTo<GuidedTour, static> $relation */
        $relation = $this->belongsTo(GuidedTour::class);

        return $relation;
    }

    /**
     * @return BelongsTo<User, static>
     */
    public function assignedBy(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'assigned_by');

        return $relation;
    }

    public function isIncomplete(): bool
    {
        return $this->status !== self::STATUS_COMPLETED;
    }
}
