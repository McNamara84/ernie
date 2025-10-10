<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $casts = [
        'lat_min' => 'decimal:6',
        'lat_max' => 'decimal:6',
        'lon_min' => 'decimal:6',
        'lon_max' => 'decimal:6',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
