/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import L from 'leaflet';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock react-leaflet components since they require browser APIs
vi.mock('react-leaflet', () => ({
    MapContainer: vi.fn(({ children }) => (
        <div data-testid="map-container">{children}</div>
    )),
    TileLayer: vi.fn(() => <div data-testid="tile-layer" />),
    Marker: vi.fn(({ position }) => (
        <div data-testid="marker" data-position={JSON.stringify(position)} />
    )),
    Rectangle: vi.fn(({ bounds }) => (
        <div data-testid="rectangle" data-bounds={JSON.stringify(bounds)} />
    )),
    Polygon: vi.fn(({ positions }) => (
        <div data-testid="polygon" data-positions={JSON.stringify(positions)} />
    )),
    useMap: vi.fn(() => ({
        fitBounds: vi.fn(),
        invalidateSize: vi.fn(),
        getContainer: vi.fn(() => document.createElement('div')),
    })),
}));

// Mock leaflet
vi.mock('leaflet', async () => {
    const actual = await vi.importActual<typeof L>('leaflet');
    return {
        ...actual,
        default: {
            ...actual,
            latLngBounds: vi.fn((points) => ({
                isValid: () => points.length > 0,
                _southWest: points[0],
                _northEast: points[points.length - 1],
            })),
            Icon: {
                Default: {
                    prototype: {},
                    mergeOptions: vi.fn(),
                },
            },
        },
        latLngBounds: vi.fn((points) => ({
            isValid: () => points.length > 0,
        })),
    };
});

// Mock leaflet CSS import
vi.mock('leaflet/dist/leaflet.css', () => ({}));

// Mock marker icons
vi.mock('leaflet/dist/images/marker-icon-2x.png', () => ({ default: 'marker-icon-2x.png' }));
vi.mock('leaflet/dist/images/marker-icon.png', () => ({ default: 'marker-icon.png' }));
vi.mock('leaflet/dist/images/marker-shadow.png', () => ({ default: 'marker-shadow.png' }));

import { LocationSection } from '@/pages/LandingPages/components/LocationSection';

describe('LocationSection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('should not render when geoLocations is empty', () => {
            const { container } = render(<LocationSection geoLocations={[]} />);
            expect(container.firstChild).toBeNull();
        });

        it('should not render when geoLocations only has place names without coordinates', () => {
            const { container } = render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Some Place',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                    ]}
                />,
            );
            expect(container.firstChild).toBeNull();
        });

        it('should render the section title', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Test Location',
                            point_longitude: 13.0661,
                            point_latitude: 52.3806,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                    ]}
                />,
            );

            expect(screen.getByText('Location')).toBeInTheDocument();
        });

        it('should render MapContainer when valid coordinates exist', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'GFZ Potsdam',
                            point_longitude: 13.0661,
                            point_latitude: 52.3806,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('map-container')).toBeInTheDocument();
        });
    });

    describe('point locations', () => {
        it('should render a Marker for point coordinates', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Test Point',
                            point_longitude: 13.0661,
                            point_latitude: 52.3806,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                    ]}
                />,
            );

            const marker = screen.getByTestId('marker');
            expect(marker).toBeInTheDocument();
            expect(marker.dataset.position).toBe('[52.3806,13.0661]');
        });

        it('should render multiple Markers for multiple points', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Point 1',
                            point_longitude: 10.0,
                            point_latitude: 50.0,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                        {
                            id: 2,
                            place: 'Point 2',
                            point_longitude: 12.0,
                            point_latitude: 52.0,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                    ]}
                />,
            );

            const markers = screen.getAllByTestId('marker');
            expect(markers).toHaveLength(2);
        });
    });

    describe('bounding box locations', () => {
        it('should render a Rectangle for bounding box coordinates', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Germany',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: 5.87,
                            east_bound_longitude: 15.04,
                            south_bound_latitude: 47.27,
                            north_bound_latitude: 55.06,
                            polygon_points: null,
                        },
                    ]}
                />,
            );

            const rectangle = screen.getByTestId('rectangle');
            expect(rectangle).toBeInTheDocument();

            const bounds = JSON.parse(rectangle.dataset.bounds || '[]');
            expect(bounds).toEqual([
                [47.27, 5.87],
                [55.06, 15.04],
            ]);
        });
    });

    describe('polygon locations', () => {
        it('should render a Polygon for polygon coordinates', () => {
            const polygonPoints = [
                { longitude: 9.19, latitude: 47.66 },
                { longitude: 9.37, latitude: 47.5 },
                { longitude: 9.63, latitude: 47.5 },
                { longitude: 9.19, latitude: 47.66 },
            ];

            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Lake Constance',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: polygonPoints,
                        },
                    ]}
                />,
            );

            const polygon = screen.getByTestId('polygon');
            expect(polygon).toBeInTheDocument();

            const positions = JSON.parse(polygon.dataset.positions || '[]');
            expect(positions).toEqual([
                [47.66, 9.19],
                [47.5, 9.37],
                [47.5, 9.63],
                [47.66, 9.19],
            ]);
        });

        it('should not render Polygon with less than 3 points', () => {
            const { container } = render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Invalid Polygon',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: [
                                { longitude: 9.19, latitude: 47.66 },
                                { longitude: 9.37, latitude: 47.5 },
                            ],
                        },
                    ]}
                />,
            );

            // Should not render since polygon has < 3 points
            expect(container.firstChild).toBeNull();
        });
    });

    describe('mixed locations', () => {
        it('should render all geometry types together', () => {
            render(
                <LocationSection
                    geoLocations={[
                        // Point
                        {
                            id: 1,
                            place: 'Berlin',
                            point_longitude: 13.405,
                            point_latitude: 52.52,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                        // Box
                        {
                            id: 2,
                            place: 'Bavaria',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: 8.97,
                            east_bound_longitude: 13.84,
                            south_bound_latitude: 47.27,
                            north_bound_latitude: 50.56,
                            polygon_points: null,
                        },
                        // Polygon
                        {
                            id: 3,
                            place: 'Alps',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: [
                                { longitude: 10, latitude: 47.5 },
                                { longitude: 12, latitude: 47 },
                                { longitude: 14, latitude: 47.5 },
                                { longitude: 10, latitude: 47.5 },
                            ],
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('marker')).toBeInTheDocument();
            expect(screen.getByTestId('rectangle')).toBeInTheDocument();
            expect(screen.getByTestId('polygon')).toBeInTheDocument();
        });
    });

    describe('TileLayer', () => {
        it('should render TileLayer with OpenStreetMap', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Test',
                            point_longitude: 10.0,
                            point_latitude: 50.0,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('tile-layer')).toBeInTheDocument();
        });
    });
});
