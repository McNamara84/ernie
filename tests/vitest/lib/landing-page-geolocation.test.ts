import { describe, expect, it } from 'vitest';

import { type GeoLocation, hasBox, hasInvalidGeometry, hasLine, hasPoint, hasPolygon } from '@/lib/landing-page-geolocation';

const empty: GeoLocation = {
    id: 1,
    place: null,
    geo_type: null,
    point_latitude: null,
    point_longitude: null,
    west_bound_longitude: null,
    east_bound_longitude: null,
    south_bound_latitude: null,
    north_bound_latitude: null,
    polygon_points: null,
};

describe('landing page geometry validation', () => {
    it.each([null, undefined, '', '52', NaN, Infinity, -Infinity, 91, -91])('rejects invalid latitude %s', (latitude) => {
        const geo = { ...empty, point_latitude: latitude, point_longitude: 13 } as GeoLocation;
        expect(hasPoint(geo)).toBe(false);
        expect(hasInvalidGeometry(geo)).toBe(true);
    });
    it.each([-90, -89.999997, 0, 90])('retains valid latitude %s without modifying it', (latitude) => {
        const geo = { ...empty, point_latitude: latitude, point_longitude: 0 };
        expect(hasPoint(geo)).toBe(true);
        expect(hasInvalidGeometry(geo)).toBe(false);
        expect(geo.point_latitude).toBe(latitude);
    });
    it.each([181, -181, NaN, Infinity])('rejects invalid longitude %s', (longitude) => {
        expect(hasPoint({ ...empty, point_latitude: 0, point_longitude: longitude })).toBe(false);
    });
    it('accepts boxes over the date line but rejects incomplete or reversed latitude bounds', () => {
        const box = { ...empty, west_bound_longitude: 170, east_bound_longitude: -170, south_bound_latitude: -10, north_bound_latitude: 10 };
        expect(hasBox(box)).toBe(true);
        expect(hasBox({ ...box, south_bound_latitude: 11 })).toBe(false);
        expect(hasBox({ ...box, east_bound_longitude: null })).toBe(false);
    });
    it.each([null, {}, '[]', [null], [{ latitude: 52 }], [{ latitude: NaN, longitude: 13 }]])('rejects malformed paths %j', (points) => {
        const geo = { ...empty, geo_type: 'polygon', polygon_points: points } as GeoLocation;
        expect(hasPolygon(geo)).toBe(false);
        expect(hasLine({ ...geo, geo_type: 'line' })).toBe(false);
    });
    it('preserves an entire valid path and rejects repeated points without repairing them', () => {
        const points = [
            { latitude: 52, longitude: 13 },
            { latitude: 53, longitude: 13 },
            { latitude: 53, longitude: 14 },
        ];
        expect(hasPolygon({ ...empty, polygon_points: points })).toBe(true);
        expect(hasPolygon({ ...empty, polygon_points: [points[0], points[0], points[0]] })).toBe(false);
        expect(hasLine({ ...empty, geo_type: 'line', polygon_points: points.slice(0, 2) })).toBe(true);
        expect(hasPolygon({ ...empty, geo_type: 'line', polygon_points: points })).toBe(false);
        expect(hasLine({ ...empty, polygon_points: points })).toBe(false);
        expect(hasInvalidGeometry({ ...empty, polygon_points: [] })).toBe(true);
    });
    it.each(['', '   ', false, null, 'bad', 'NaN', '1e309', 91, Infinity, NaN, []])(
        'rejects a malformed coordinate in an otherwise complete path: %j',
        (latitude) => {
            const points = [
                { latitude, longitude: 13.06 },
                { latitude: 53, longitude: 13 },
                { latitude: 53, longitude: 14 },
            ];
            const geo = { ...empty, geo_type: 'polygon', polygon_points: points } as unknown as GeoLocation;
            expect(hasPolygon(geo)).toBe(false);
            expect(hasLine({ ...geo, geo_type: 'line' })).toBe(false);
            expect(hasInvalidGeometry(geo)).toBe(true);
        },
    );
    it('retains a valid point while identifying a malformed sibling geometry', () => {
        const geo = { ...empty, point_latitude: 52, point_longitude: 13, polygon_points: [] };
        expect(hasPoint(geo)).toBe(true);
        expect(hasInvalidGeometry(geo)).toBe(true);
        expect(hasInvalidGeometry(empty)).toBe(false);
    });
});
