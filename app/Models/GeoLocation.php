<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GeoLocation Model (DataCite #18)
 *
 * Stores geographic locations for a Resource.
 *
 * @property int $id
 * @property int $resource_id
 * @property string|null $place
 * @property float|null $point_longitude
 * @property float|null $point_latitude
 * @property float|null $west_bound_longitude
 * @property float|null $east_bound_longitude
 * @property float|null $south_bound_latitude
 * @property float|null $north_bound_latitude
 * @property array<array{longitude: float, latitude: float}>|null $polygon_points
 * @property float|null $in_polygon_point_longitude
 * @property float|null $in_polygon_point_latitude
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GeoLocationPolygon> $polygons
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/geolocation/
 */
class GeoLocation extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'place',
        'point_longitude',
        'point_latitude',
        'west_bound_longitude',
        'east_bound_longitude',
        'south_bound_latitude',
        'north_bound_latitude',
        'polygon_points',
        'in_polygon_point_longitude',
        'in_polygon_point_latitude',
    ];

    protected $casts = [
        'point_longitude' => 'decimal:8',
        'point_latitude' => 'decimal:8',
        'west_bound_longitude' => 'decimal:8',
        'east_bound_longitude' => 'decimal:8',
        'south_bound_latitude' => 'decimal:8',
        'north_bound_latitude' => 'decimal:8',
        'polygon_points' => 'array',
        'in_polygon_point_longitude' => 'decimal:8',
        'in_polygon_point_latitude' => 'decimal:8',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return HasMany<GeoLocationPolygon, static> */
    public function polygons(): HasMany
    {
        /** @var HasMany<GeoLocationPolygon, static> $relation */
        $relation = $this->hasMany(GeoLocationPolygon::class);

        return $relation;
    }

    /**
     * Check if this has a point defined.
     */
    public function hasPoint(): bool
    {
        return $this->point_longitude !== null
            && $this->point_latitude !== null;
    }

    /**
     * Check if this has a bounding box defined.
     */
    public function hasBox(): bool
    {
        return $this->west_bound_longitude !== null
            && $this->east_bound_longitude !== null
            && $this->south_bound_latitude !== null
            && $this->north_bound_latitude !== null;
    }

    /**
     * Check if this has a place name defined.
     */
    public function hasPlace(): bool
    {
        return $this->place !== null;
    }

    /**
     * Check if this has polygon points defined.
     */
    public function hasPolygon(): bool
    {
        return $this->polygon_points !== null && count($this->polygon_points) >= 4;
    }
}
