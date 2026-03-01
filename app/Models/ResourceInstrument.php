<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resource Instrument Model (PID4INST)
 *
 * Stores instruments linked to a Resource via PID4INST persistent identifiers.
 * Exported as relatedIdentifier elements in DataCite with relationType="IsCollectedBy".
 *
 * @property int $id
 * @property int $resource_id
 * @property string $instrument_pid
 * @property string $instrument_pid_type
 * @property string $instrument_name
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 *
 * @see https://docs.pidinst.org/en/latest/white-paper/linking-datasets.html
 */
class ResourceInstrument extends Model
{
    /** @use HasFactory<\Database\Factories\ResourceInstrumentFactory> */
    use HasFactory;
    /**
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'instrument_pid',
        'instrument_pid_type',
        'instrument_name',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Resource, static>
     */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
