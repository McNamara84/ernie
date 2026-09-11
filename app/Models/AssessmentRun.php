<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
use Database\Factories\AssessmentRunFactory;
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
 * @property AssessmentScope $scope
 * @property AssessmentRunStatus $status
 * @property AssessmentScope|null $active_scope
 * @property int|null $initiated_by_user_id
 * @property int|null $last_controlled_by_user_id
 * @property string $fuji_base_url
 * @property string|null $metric_version
 * @property bool $use_datacite
 * @property bool $use_github
 * @property int $concurrency
 * @property int $requests_per_minute
 * @property int $snapshot_max_resource_id
 * @property int $preparation_cursor
 * @property int $total
 * @property int $processed
 * @property int $assessed
 * @property int $failed
 * @property int $service_errors
 * @property int $skipped
 * @property int $pending
 * @property string|null $pause_reason
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $prepared_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $completed_at
 * @property Carbon $updated_at
 * @property-read Collection<int, AssessmentRunItem> $items
 * @property-read User|null $initiatedBy
 * @property-read User|null $lastControlledBy
 */
class AssessmentRun extends Model
{
    /** @use HasFactory<AssessmentRunFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'status',
        'active_scope',
        'initiated_by_user_id',
        'last_controlled_by_user_id',
        'fuji_base_url',
        'metric_version',
        'use_datacite',
        'use_github',
        'concurrency',
        'requests_per_minute',
        'snapshot_max_resource_id',
        'preparation_cursor',
        'total',
        'processed',
        'assessed',
        'failed',
        'service_errors',
        'skipped',
        'pending',
        'pause_reason',
        'last_error',
        'started_at',
        'prepared_at',
        'paused_at',
        'cancelled_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => AssessmentScope::class,
            'status' => AssessmentRunStatus::class,
            'active_scope' => AssessmentScope::class,
            'use_datacite' => 'boolean',
            'use_github' => 'boolean',
            'concurrency' => 'integer',
            'requests_per_minute' => 'integer',
            'snapshot_max_resource_id' => 'integer',
            'preparation_cursor' => 'integer',
            'total' => 'integer',
            'processed' => 'integer',
            'assessed' => 'integer',
            'failed' => 'integer',
            'service_errors' => 'integer',
            'skipped' => 'integer',
            'pending' => 'integer',
            'started_at' => 'datetime',
            'prepared_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<AssessmentRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AssessmentRunItem::class, 'run_id');
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
        return $query->whereNotNull('active_scope');
    }

    public function releaseActiveScope(): void
    {
        $this->active_scope = null;
    }
}
