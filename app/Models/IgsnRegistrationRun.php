<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IgsnRegistrationRunStatus;
use Database\Factories\IgsnRegistrationRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $initiated_by_user_id
 * @property int|null $last_controlled_by_user_id
 * @property IgsnRegistrationRunStatus $status
 * @property bool $test_mode
 * @property string $datacite_endpoint
 * @property int $total
 * @property int $processed
 * @property int $registered
 * @property int $updated
 * @property int $failed
 * @property int $cancelled
 * @property string|null $pause_reason
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $completed_at
 * @property-read User|null $initiatedBy
 * @property-read User|null $lastControlledBy
 * @property-read Collection<int, IgsnRegistrationItem> $items
 */
class IgsnRegistrationRun extends Model
{
    /** @use HasFactory<IgsnRegistrationRunFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'initiated_by_user_id',
        'last_controlled_by_user_id',
        'status',
        'test_mode',
        'datacite_endpoint',
        'total',
        'processed',
        'registered',
        'updated',
        'failed',
        'cancelled',
        'pause_reason',
        'last_error',
        'started_at',
        'paused_at',
        'cancelled_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => IgsnRegistrationRunStatus::class,
            'test_mode' => 'boolean',
            'total' => 'integer',
            'processed' => 'integer',
            'registered' => 'integer',
            'updated' => 'integer',
            'failed' => 'integer',
            'cancelled' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<IgsnRegistrationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(IgsnRegistrationItem::class, 'run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function lastControlledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_controlled_by_user_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', IgsnRegistrationRunStatus::activeValues());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('initiated_by_user_id', $user->id);
    }
}
