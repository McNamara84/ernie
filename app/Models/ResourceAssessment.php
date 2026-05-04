<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $resource_id
 * @property string $status
 * @property float|null $total_score
 * @property string|null $assessed_identifier
 * @property string|null $error_message
 * @property array<string, mixed>|null $payload
 * @property \Illuminate\Support\Carbon|null $assessed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 */
class ResourceAssessment extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'status',
        'total_score',
        'assessed_identifier',
        'error_message',
        'payload',
        'assessed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'total_score' => 'decimal:2',
        'payload' => 'array',
        'assessed_at' => 'datetime',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}