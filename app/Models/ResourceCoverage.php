<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $resource_id
 * @property float|null $lat_min
 * @property float|null $lat_max
 * @property float|null $lon_min
 * @property float|null $lon_max
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string $timezone
 * @property string|null $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ResourceCoverage extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'lat_min',
        'lat_max',
        'lon_min',
        'lon_max',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'timezone',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat_min' => 'decimal:6',
            'lat_max' => 'decimal:6',
            'lon_min' => 'decimal:6',
            'lon_max' => 'decimal:6',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
