<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GeoLocationPolygon Model (DataCite #18.4)
 *
 * Stores polygon points for a GeoLocation. Each polygon belongs to a GeoLocation.
 *
 * @property int $id
 * @property int $geo_location_id
 * @property float $point_longitude
 * @property float $point_latitude
 * @property int $position
 * @property bool $is_in_polygon_point
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read GeoLocation $geoLocation
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/geolocation/#geolocationpolygon
 */
class GeoLocationPolygon extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'geo_location_id',
        'point_longitude',
        'point_latitude',
        'position',
        'is_in_polygon_point',
    ];

    protected $casts = [
        'point_longitude' => 'decimal:8',
        'point_latitude' => 'decimal:8',
        'position' => 'integer',
        'is_in_polygon_point' => 'boolean',
    ];

    /** @return BelongsTo<GeoLocation, static> */
    public function geoLocation(): BelongsTo
    {
        /** @var BelongsTo<GeoLocation, static> $relation */
        $relation = $this->belongsTo(GeoLocation::class);

        return $relation;
    }
}
