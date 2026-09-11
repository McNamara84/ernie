<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssessmentFailureType;
use App\Enums\AssessmentRunItemStatus;
use Database\Factories\AssessmentRunItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $run_id
 * @property int|null $resource_id
 * @property string|null $identifier
 * @property AssessmentRunItemStatus $status
 * @property int $attempts
 * @property int|null $last_http_status
 * @property AssessmentFailureType|null $failure_type
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $error_detail
 * @property int|null $last_attempt_duration_ms
 * @property Carbon|null $available_at
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $processed_at
 * @property-read AssessmentRun $run
 * @property-read Resource|null $resource
 */
class AssessmentRunItem extends Model
{
    /** @use HasFactory<AssessmentRunItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'resource_id',
        'identifier',
        'status',
        'attempts',
        'last_http_status',
        'failure_type',
        'error_code',
        'error_message',
        'error_detail',
        'last_attempt_duration_ms',
        'available_at',
        'processing_started_at',
        'lease_expires_at',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AssessmentRunItemStatus::class,
            'attempts' => 'integer',
            'last_http_status' => 'integer',
            'failure_type' => AssessmentFailureType::class,
            'last_attempt_duration_ms' => 'integer',
            'available_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AssessmentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AssessmentRun::class, 'run_id');
    }

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
