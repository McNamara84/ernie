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
 * @property string|null $geo_location_place
 * @property float|null $geo_location_point_longitude
 * @property float|null $geo_location_point_latitude
 * @property float|null $geo_location_box_west_bound_longitude
 * @property float|null $geo_location_box_east_bound_longitude
 * @property float|null $geo_location_box_south_bound_latitude
 * @property float|null $geo_location_box_north_bound_latitude
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
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
        'geo_location_place',
        'geo_location_point_longitude',
        'geo_location_point_latitude',
        'geo_location_box_west_bound_longitude',
        'geo_location_box_east_bound_longitude',
        'geo_location_box_south_bound_latitude',
        'geo_location_box_north_bound_latitude',
    ];

    protected $casts = [
        'geo_location_point_longitude' => 'decimal:8',
        'geo_location_point_latitude' => 'decimal:8',
        'geo_location_box_west_bound_longitude' => 'decimal:8',
        'geo_location_box_east_bound_longitude' => 'decimal:8',
        'geo_location_box_south_bound_latitude' => 'decimal:8',
        'geo_location_box_north_bound_latitude' => 'decimal:8',
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
        return $this->geo_location_point_longitude !== null
            && $this->geo_location_point_latitude !== null;
    }

    /**
     * Check if this has a bounding box defined.
     */
    public function hasBox(): bool
    {
        return $this->geo_location_box_west_bound_longitude !== null
            && $this->geo_location_box_east_bound_longitude !== null
            && $this->geo_location_box_south_bound_latitude !== null
            && $this->geo_location_box_north_bound_latitude !== null;
    }

    /**
     * Check if this has a place name defined.
     */
    public function hasPlace(): bool
    {
        return $this->geo_location_place !== null;
    }
}
