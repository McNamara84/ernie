<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataCiteUrlUpdateRunStatus;
use App\Enums\DataCiteUrlUpdateScope;
use Database\Factories\DataCiteUrlUpdateRunFactory;
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
 * @property DataCiteUrlUpdateScope $scope
 * @property DataCiteUrlUpdateRunStatus $status
 * @property string|null $active_marker
 * @property int|null $initiated_by_user_id
 * @property int|null $last_controlled_by_user_id
 * @property bool $test_mode
 * @property string $datacite_endpoint
 * @property string $target_base_url
 * @property int $total
 * @property int $processed
 * @property int $updated
 * @property int $already_current
 * @property int $skipped
 * @property int $failed
 * @property string|null $pause_reason
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $completed_at
 * @property-read User|null $initiatedBy
 * @property-read User|null $lastControlledBy
 * @property-read Collection<int, DataCiteUrlUpdateItem> $items
 */
class DataCiteUrlUpdateRun extends Model
{
    /** @use HasFactory<DataCiteUrlUpdateRunFactory> */
    use HasFactory, HasUuids;

    public const ACTIVE_MARKER = 'global';

    protected $table = 'datacite_url_update_runs';

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'status',
        'active_marker',
        'initiated_by_user_id',
        'last_controlled_by_user_id',
        'test_mode',
        'datacite_endpoint',
        'target_base_url',
        'total',
        'processed',
        'updated',
        'already_current',
        'skipped',
        'failed',
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
            'scope' => DataCiteUrlUpdateScope::class,
            'status' => DataCiteUrlUpdateRunStatus::class,
            'test_mode' => 'boolean',
            'total' => 'integer',
            'processed' => 'integer',
            'updated' => 'integer',
            'already_current' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<DataCiteUrlUpdateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DataCiteUrlUpdateItem::class, 'run_id');
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
        return $query->whereNotNull('active_marker');
    }

    public function releaseActiveMarker(): void
    {
        $this->active_marker = null;
    }
}
