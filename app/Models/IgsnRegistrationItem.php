<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IgsnRegistrationItemStatus;
use Database\Factories\IgsnRegistrationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $run_id
 * @property int|null $resource_id
 * @property string $identifier
 * @property IgsnRegistrationItemStatus $status
 * @property string|null $operation
 * @property int $attempts
 * @property int|null $last_http_status
 * @property string|null $error_message
 * @property Carbon|null $processed_at
 * @property-read IgsnRegistrationRun $run
 * @property-read Resource|null $resource
 */
class IgsnRegistrationItem extends Model
{
    /** @use HasFactory<IgsnRegistrationItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'resource_id',
        'identifier',
        'status',
        'operation',
        'attempts',
        'last_http_status',
        'error_message',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => IgsnRegistrationItemStatus::class,
            'attempts' => 'integer',
            'last_http_status' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IgsnRegistrationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(IgsnRegistrationRun::class, 'run_id');
    }

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
