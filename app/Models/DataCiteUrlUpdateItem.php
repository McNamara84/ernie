<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataCiteUrlUpdateItemStatus;
use Database\Factories\DataCiteUrlUpdateItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $run_id
 * @property int|null $resource_id
 * @property string $identifier
 * @property DataCiteUrlUpdateItemStatus $status
 * @property string|null $before_url
 * @property string $target_url
 * @property string|null $datacite_state
 * @property int $preflight_attempts
 * @property int $update_attempts
 * @property int|null $last_http_status
 * @property string|null $error_message
 * @property Carbon|null $processed_at
 * @property-read DataCiteUrlUpdateRun $run
 * @property-read Resource|null $resource
 */
class DataCiteUrlUpdateItem extends Model
{
    /** @use HasFactory<DataCiteUrlUpdateItemFactory> */
    use HasFactory;

    protected $table = 'datacite_url_update_items';

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'resource_id',
        'identifier',
        'status',
        'before_url',
        'target_url',
        'datacite_state',
        'preflight_attempts',
        'update_attempts',
        'last_http_status',
        'error_message',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DataCiteUrlUpdateItemStatus::class,
            'preflight_attempts' => 'integer',
            'update_attempts' => 'integer',
            'last_http_status' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DataCiteUrlUpdateRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DataCiteUrlUpdateRun::class, 'run_id');
    }

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
