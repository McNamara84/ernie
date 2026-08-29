import { describe, expect, it } from 'vitest';

import { rebaseLongitude, unwrapLongitudeBounds, unwrapPathLongitudes } from '@/lib/portal-map-longitude';

describe('portal map longitude utilities', () => {
    it('rebases normalized longitudes to the world copy nearest the map center', () => {
        expect(rebaseLongitude(-179, 180)).toBe(181);
        expect(rebaseLongitude(179, -180)).toBe(-181);
        expect(rebaseLongitude(13, 180)).toBe(13);
    });

    it('unwraps circular bounds into their short Leaflet interval', () => {
        expect(unwrapLongitudeBounds({ west: 170, east: -170 }, 180)).toEqual({ west: 170, east: 190 });
        expect(unwrapLongitudeBounds({ west: 170, east: -170 }, -180)).toEqual({ west: -190, east: -170 });
        expect(unwrapLongitudeBounds({ west: 12, east: 14 }, 180)).toEqual({ west: 12, east: 14 });
    });

    it('keeps consecutive path points on the same world copy', () => {
        expect(
            unwrapPathLongitudes(
                [
                    { latitude: -10, longitude: 179 },
                    { latitude: 10, longitude: -179 },
                    { latitude: 0, longitude: 178 },
                ],
                180,
            ),
        ).toEqual([
            { latitude: -10, longitude: 179 },
            { latitude: 10, longitude: 181 },
            { latitude: 0, longitude: 178 },
        ]);
    });
});
