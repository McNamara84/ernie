<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $resource_id
 * @property string $status
 * @property int $generation
 * @property string|null $claim_token
 * @property int|null $queue_job_id
 * @property int $attempts
 * @property int $service_attempts
 * @property Carbon $requested_at
 * @property Carbon|null $available_at
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $completed_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resource $resource
 */
final class ResourceAssessmentRefresh extends Model
{
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $primaryKey = 'resource_id';

    public $incrementing = false;

    protected $fillable = ['resource_id', 'status', 'generation', 'claim_token', 'queue_job_id', 'attempts', 'service_attempts', 'requested_at', 'available_at', 'lease_expires_at', 'completed_at', 'last_error'];

    protected $casts = [
        'generation' => 'integer',
        'queue_job_id' => 'integer',
        'attempts' => 'integer',
        'service_attempts' => 'integer',
        'requested_at' => 'datetime',
        'available_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function setRequestedAtAttribute(Carbon|string $value): void
    {
        // Eloquent's default date format drops microseconds before the database write.
        $this->attributes['requested_at'] = Carbon::parse($value)->format('Y-m-d H:i:s.u');
    }

    public function originalIsEquivalent($key): bool
    {
        if ($key === 'requested_at' && array_key_exists($key, $this->original)) {
            // Eloquent's default date comparison also drops microseconds.
            return ($this->attributes[$key] ?? null) === $this->original[$key];
        }

        return parent::originalIsEquivalent($key);
    }

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
