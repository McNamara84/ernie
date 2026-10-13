import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import LineForm from '@/components/curation/fields/spatial-temporal-coverage/LineForm';
import PolygonForm from '@/components/curation/fields/spatial-temporal-coverage/PolygonForm';
import type { PolygonPoint, SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

const maps = vi.hoisted(() => ({ map: { fitBounds: vi.fn(), addListener: vi.fn(() => ({})) } }));
vi.mock('@vis.gl/react-google-maps', () => ({
    APIProvider: ({ children }: { children: React.ReactNode }) => children,
    Map: () => <div />,
    useMap: () => maps.map,
}));

type DrawnCoordinate = { lat: number; lng: number };
const shapes: Array<{ coordinates: DrawnCoordinate[] }> = [];
const markers: Array<{ options: { position: DrawnCoordinate; label: { text: string } }; drag: () => void }> = [];
const extendedBounds: DrawnCoordinate[] = [];
const pathListeners: Array<() => void> = [];

beforeEach(() => {
    vi.clearAllMocks();
    shapes.length = 0;
    markers.length = 0;
    extendedBounds.length = 0;
    pathListeners.length = 0;
    const validate = (point: DrawnCoordinate) => {
        if (!Number.isFinite(point.lat) || !Number.isFinite(point.lng) || Math.abs(point.lat) > 90 || Math.abs(point.lng) > 180) {
            throw new Error('Invalid Google Maps coordinate');
        }
    };
    class Shape {
        coordinates: DrawnCoordinate[];
        constructor(options: { paths?: DrawnCoordinate[]; path?: DrawnCoordinate[] }) {
            this.coordinates = options.paths ?? options.path ?? [];
            this.coordinates.forEach(validate);
            shapes.push(this);
        }
        setMap() {}
        getPath() {
            return {
                getLength: () => this.coordinates.length,
                getAt: (index: number) => ({ lat: () => this.coordinates[index].lat, lng: () => this.coordinates[index].lng }),
            };
        }
    }
    vi.stubGlobal('google', {
        maps: {
            Polygon: Shape,
            Polyline: Shape,
            Marker: class {
                options: { position: DrawnCoordinate; label: { text: string } };
                drag = () => {};
                constructor(options: { position: DrawnCoordinate; label: { text: string } }) {
                    validate(options.position);
                    this.options = options;
                    markers.push(this);
                }
                setMap() {}
                addListener(_event: string, callback: () => void) {
                    this.drag = callback;
                }
                getPosition() {
                    return { lat: () => 54, lng: () => 14 };
                }
            },
            LatLngBounds: class {
                extend(point: DrawnCoordinate) {
                    validate(point);
                    extendedBounds.push(point);
                }
            },
            SymbolPath: { CIRCLE: 'circle' },
            event: {
                addListener: (_path: unknown, _event: string, callback: () => void) => pathListeners.push(callback),
                removeListener: vi.fn(),
            },
        },
    });
});

const entry: SpatialTemporalCoverageEntry = {
    id: 'historical',
    type: 'polygon',
    latMin: '',
    latMax: '',
    lonMin: '',
    lonMax: '',
    startDate: '',
    endDate: '',
    startTime: '',
    endTime: '',
    timezone: '',
    description: '',
};
const incomplete: PolygonPoint[] = [
    { lat: null, lon: 13 },
    { lat: 52, lon: 13 },
    { lat: 500, lon: 10 },
    { lat: 0, lon: null },
    { lat: 0, lon: 0 },
];

describe.each([
    ['polygon', PolygonForm],
    ['line', LineForm],
] as const)('%s editor with historical incomplete points', (type, Form) => {
    const setup = (points = incomplete) => {
        const onBatchChange = vi.fn();
        const props = { entry: { ...entry, type, polygonPoints: points }, apiKey: 'test-key', onChange: vi.fn(), onBatchChange };
        const result = render(<Form {...props} />);
        return { ...result, props, onBatchChange };
    };

    it('keeps blank inputs and passes only finite in-range coordinates to every map API', () => {
        setup();
        expect(shapes[0].coordinates).toEqual([
            { lat: 52, lng: 13 },
            { lat: 0, lng: 0 },
        ]);
        expect(extendedBounds).toEqual(shapes[0].coordinates);
        expect(markers.map((marker) => marker.options.label.text)).toEqual(['2', '5']);
        const inputs = screen.getAllByRole('spinbutton') as HTMLInputElement[];
        expect(inputs).toHaveLength(10);
        expect(inputs[0].value).toBe('');
        expect(inputs[7].value).toBe('');
        expect(inputs[8].value).toBe('0');
    });

    it('renders completely incomplete geometry without drawing or fitting invalid bounds', () => {
        setup([
            { lat: null, lon: null },
            { lat: null, lon: null },
        ]);
        expect(shapes).toEqual([]);
        expect(markers).toEqual([]);
        expect(maps.map.fitBounds).not.toHaveBeenCalled();
        expect(screen.getAllByRole('spinbutton')).toHaveLength(4);
    });

    it('preserves omitted rows when dragging a valid marker', () => {
        const { onBatchChange } = setup();
        markers[0].drag();
        expect(onBatchChange).toHaveBeenCalledWith({ polygonPoints: [incomplete[0], { lat: 54, lon: 14 }, ...incomplete.slice(2)] });
    });

    it('preserves incomplete rows when a map path changes', () => {
        const { onBatchChange } = setup();
        shapes[0].coordinates[0] = { lat: 54, lng: 14 };
        pathListeners[0]();
        expect(onBatchChange).toHaveBeenCalledWith({ polygonPoints: [incomplete[0], { lat: 54, lon: 14 }, ...incomplete.slice(2)] });
    });

    it('allows manual correction to zero and draws the corrected coordinate on rerender', () => {
        const { props, onBatchChange, rerender } = setup([{ lat: null, lon: 13 }]);
        fireEvent.change(screen.getAllByRole('spinbutton')[0], { target: { value: '0' } });
        expect(onBatchChange).toHaveBeenCalledWith({ polygonPoints: [{ lat: 0, lon: 13 }] });
        rerender(<Form {...props} entry={{ ...props.entry, polygonPoints: [{ lat: 0, lon: 13 }] }} />);
        expect(shapes[0].coordinates).toEqual([{ lat: 0, lng: 13 }]);
    });

    it('keeps a cleared coordinate missing instead of substituting zero', () => {
        const { onBatchChange } = setup([{ lat: 52, lon: 13 }]);
        fireEvent.change(screen.getAllByRole('spinbutton')[0], { target: { value: '' } });
        expect(onBatchChange).toHaveBeenCalledWith({ polygonPoints: [{ lat: null, lon: 13 }] });
    });
});
