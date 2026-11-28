<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $metadata_prefix
 * @property string $oai_identifier
 * @property string $doi
 * @property int|null $resource_id
 * @property string $status
 * @property Carbon $first_available_at
 * @property Carbon $datestamp
 * @property list<string> $sets
 * @property string|null $resource_type
 * @property bool $is_igsn
 * @property int $export_revision
 * @property string|null $fingerprint
 * @property-read Resource|null $resource
 */
final class OaiPmhFormatRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sets' => 'array', 'datestamp' => 'datetime', 'first_available_at' => 'datetime',
        'is_igsn' => 'boolean', 'export_revision' => 'integer', 'resource_id' => 'integer',
    ];

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
