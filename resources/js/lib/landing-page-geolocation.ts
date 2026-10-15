import type L from 'leaflet';

export interface GeoLocation {
    id: number;
    place: string | null;
    point_longitude: number | null;
    point_latitude: number | null;
    west_bound_longitude: number | null;
    east_bound_longitude: number | null;
    south_bound_latitude: number | null;
    north_bound_latitude: number | null;
    polygon_points: Array<{ longitude: number; latitude: number }> | null;
    geo_type: string | null;
    elevation?: number | null;
    elevation_unit?: string | null;
    location_type?: string | null;
    location_description?: string | null;
    locality_description?: string | null;
    country?: string | null;
    province?: string | null;
    county?: string | null;
    city?: string | null;
}

export const LOCATION_FIT_OPTIONS: L.FitBoundsOptions = { padding: [20, 20], maxZoom: 2 };

function coordinate(value: unknown, limit: number): value is number {
    return typeof value === 'number' && Number.isFinite(value) && Math.abs(value) <= limit;
}

export function hasPoint(geo: GeoLocation): boolean {
    return coordinate(geo.point_latitude, 90) && coordinate(geo.point_longitude, 180);
}

export function hasBox(geo: GeoLocation): boolean {
    return (
        coordinate(geo.south_bound_latitude, 90) &&
        coordinate(geo.north_bound_latitude, 90) &&
        coordinate(geo.west_bound_longitude, 180) &&
        coordinate(geo.east_bound_longitude, 180) &&
        geo.south_bound_latitude <= geo.north_bound_latitude
    );
}

function validPath(geo: GeoLocation, minimum: number): boolean {
    const points = geo.polygon_points;
    if (
        !Array.isArray(points) ||
        points.length < minimum ||
        !points.every((point) => point && coordinate(point.latitude, 90) && coordinate(point.longitude, 180))
    ) {
        return false;
    }

    return new Set(points.map((point) => `${point.latitude},${point.longitude}`)).size >= minimum;
}

export function hasPolygon(geo: GeoLocation): boolean {
    return geo.geo_type !== 'line' && validPath(geo, 3);
}

export function hasLine(geo: GeoLocation): boolean {
    return geo.geo_type === 'line' && validPath(geo, 2);
}

export function hasInvalidGeometry(geo: GeoLocation): boolean {
    const present = (values: unknown[]) => values.some((value) => value !== null && value !== undefined);
    return (
        (present([geo.point_latitude, geo.point_longitude]) && !hasPoint(geo)) ||
        (present([geo.south_bound_latitude, geo.north_bound_latitude, geo.west_bound_longitude, geo.east_bound_longitude]) && !hasBox(geo)) ||
        (geo.polygon_points !== null && geo.polygon_points !== undefined && !(geo.geo_type === 'line' ? hasLine(geo) : hasPolygon(geo)))
    );
}
