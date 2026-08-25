/**
 * @vitest-environment jsdom
 */
import { render, screen, within } from '@testing-library/react';
import L from 'leaflet';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock react-leaflet components since they require browser APIs
vi.mock('react-leaflet', () => ({
    MapContainer: vi.fn(({ children, maxBounds, maxBoundsViscosity, worldCopyJump }) => (
        <div
            data-testid="leaflet-map"
            data-max-bounds={JSON.stringify(maxBounds)}
            data-max-bounds-viscosity={String(maxBoundsViscosity)}
            data-world-copy-jump={String(worldCopyJump)}
        >
            {children}
        </div>
    )),
    TileLayer: vi.fn(({ noWrap, url }) => <div data-testid="tile-layer" data-no-wrap={String(noWrap)} data-url={url} />),
    Marker: vi.fn(({ position, children }) => (
        <div data-testid="marker" data-position={JSON.stringify(position)}>
            {children}
        </div>
    )),
    Popup: vi.fn(({ children }) => <div data-testid="popup">{children}</div>),
    Rectangle: vi.fn(({ bounds }) => <div data-testid="rectangle" data-bounds={JSON.stringify(bounds)} />),
    Polygon: vi.fn(({ positions }) => <div data-testid="polygon" data-positions={JSON.stringify(positions)} />),
    Polyline: vi.fn(({ positions }) => <div data-testid="polyline" data-positions={JSON.stringify(positions)} />),
    CircleMarker: vi.fn(({ center, children }) => (
        <div data-testid="circle-marker" data-center={JSON.stringify(center)}>
            {children}
        </div>
    )),
    Tooltip: vi.fn(({ children, direction, sticky }) => (
        <div data-testid="tooltip" data-direction={direction} data-sticky={String(sticky)}>
            {children}
        </div>
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
                            geo_type: null,
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
                            geo_type: null,
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
                            geo_type: null,
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('map-container')).toBeInTheDocument();
        });

        it('should render maps with a 1:1 aspect ratio and bounded world tiles', () => {
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
                            geo_type: 'point',
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('map-container')).toHaveClass('aspect-square');
            expect(screen.getByTestId('leaflet-map').dataset.maxBounds).toBe('[[-90,-180],[90,180]]');
            expect(screen.getByTestId('leaflet-map').dataset.maxBoundsViscosity).toBe('1');
            expect(screen.getByTestId('leaflet-map').dataset.worldCopyJump).toBe('false');
            expect(screen.getByTestId('tile-layer').dataset.noWrap).toBe('true');
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
                            geo_type: null,
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
                            geo_type: null,
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
                            geo_type: null,
                        },
                    ]}
                />,
            );

            const markers = screen.getAllByTestId('marker');
            expect(markers).toHaveLength(2);
        });

        it('should render the location description in the point marker popup', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'KOU Kourou maintained by: Bureau Central de Magnétisme Terrestre, BCMT',
                            point_longitude: -52.73,
                            point_latitude: 5.21,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                            geo_type: 'point',
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('popup')).toHaveTextContent('KOU Kourou maintained by: Bureau Central de Magnétisme Terrestre, BCMT');
        });

        it('should keep each description associated with its point marker', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'KOU Kourou',
                            point_longitude: -52.73,
                            point_latitude: 5.21,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                            geo_type: 'point',
                        },
                        {
                            id: 2,
                            place: 'NGK Niemegk',
                            point_longitude: 12.68,
                            point_latitude: 52.07,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                            geo_type: 'point',
                        },
                    ]}
                />,
            );

            const markers = screen.getAllByTestId('marker');
            expect(within(markers[0]).getByTestId('popup')).toHaveTextContent('KOU Kourou');
            expect(within(markers[0]).queryByText('NGK Niemegk')).not.toBeInTheDocument();
            expect(within(markers[1]).getByTestId('popup')).toHaveTextContent('NGK Niemegk');
            expect(within(markers[1]).queryByText('KOU Kourou')).not.toBeInTheDocument();
        });

        it('should not render empty popups for point markers without descriptions', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: null,
                            point_longitude: 10.0,
                            point_latitude: 50.0,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                            geo_type: 'point',
                        },
                        {
                            id: 2,
                            place: '   ',
                            point_longitude: 12.0,
                            point_latitude: 52.0,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                            geo_type: 'point',
                        },
                    ]}
                />,
            );

            expect(screen.getAllByTestId('marker')).toHaveLength(2);
            expect(screen.queryByTestId('popup')).not.toBeInTheDocument();
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
                            geo_type: null,
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

        it('renders the approved GFLMU0020 sampling-location rows and rectangle, never a line', () => {
            render(
                <LocationSection
                    samplingLocation
                    igsn={{ coordinate_system: null } as never}
                    geoLocations={[
                        {
                            id: 20,
                            place: 'Heppenheim/Bergstraße, Germany',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: 8.68799,
                            east_bound_longitude: 8.69644,
                            south_bound_latitude: 49.6288,
                            north_bound_latitude: 49.6344,
                            polygon_points: null,
                            geo_type: 'box',
                            country: 'Germany',
                            city: 'Heppenheim',
                        },
                    ]}
                />,
            );

            expect(screen.getByRole('heading', { name: 'Sampling Location' })).toBeInTheDocument();
            expect(screen.getByText('49.628800')).toBeInTheDocument();
            expect(screen.getByText('8.687990')).toBeInTheDocument();
            expect(screen.getByText('49.634400')).toBeInTheDocument();
            expect(screen.getByText('8.696440')).toBeInTheDocument();
            expect(screen.getByText('Heppenheim/Bergstraße, Germany')).toBeInTheDocument();
            expect(screen.getByTestId('rectangle')).toBeInTheDocument();
            expect(screen.queryByTestId('polyline')).not.toBeInTheDocument();
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
                            geo_type: null,
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
                            geo_type: null,
                        },
                    ]}
                />,
            );

            // Should not render since polygon has < 3 points
            expect(container.firstChild).toBeNull();
        });
    });

    describe('line locations', () => {
        it('should render a Polyline and coordinate tooltips for every waypoint', () => {
            const linePoints = [
                { longitude: 13.0661, latitude: 52.3806 },
                { longitude: 14.1234, latitude: 53.9876 },
                { longitude: 15.5, latitude: 54.25 },
            ];

            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'Survey line',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: linePoints,
                            geo_type: 'line',
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('polyline')).toBeInTheDocument();
            expect(screen.getAllByTestId('circle-marker')).toHaveLength(3);
            expect(screen.getAllByTestId('tooltip')).toHaveLength(3);
            expect(screen.getByText('Lat: 52.380600, Lon: 13.066100')).toBeInTheDocument();
            expect(screen.getByText('Lat: 53.987600, Lon: 14.123400')).toBeInTheDocument();
            expect(screen.getByText('Lat: 54.250000, Lon: 15.500000')).toBeInTheDocument();
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
                            geo_type: null,
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
                            geo_type: null,
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
                            geo_type: null,
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('marker')).toBeInTheDocument();
            expect(screen.getByTestId('rectangle')).toBeInTheDocument();
            expect(screen.getByTestId('polygon')).toBeInTheDocument();
        });

        it('should show a global coverage message without rendering a map for global-only coverage', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'World',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: -180,
                            east_bound_longitude: 180,
                            south_bound_latitude: -90,
                            north_bound_latitude: 90,
                            polygon_points: null,
                            geo_type: 'box',
                        },
                    ]}
                />,
            );

            expect(screen.getByText('Location')).toBeInTheDocument();
            expect(screen.getByTestId('global-coverage-message')).toHaveTextContent('This dataset has global spatial coverage.');
            expect(screen.queryByTestId('map-container')).not.toBeInTheDocument();
            expect(screen.queryByTestId('rectangle')).not.toBeInTheDocument();
        });

        it('should render local point geometry and the message for mixed global and point coverage', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'World',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: -180,
                            east_bound_longitude: 180,
                            south_bound_latitude: -90,
                            north_bound_latitude: 90,
                            polygon_points: null,
                            geo_type: 'box',
                        },
                        {
                            id: 2,
                            place: 'Potsdam',
                            point_longitude: 13.0661,
                            point_latitude: 52.3806,
                            west_bound_longitude: null,
                            east_bound_longitude: null,
                            south_bound_latitude: null,
                            north_bound_latitude: null,
                            polygon_points: null,
                            geo_type: 'point',
                        },
                    ]}
                />,
            );

            expect(screen.getByTestId('global-coverage-message')).toBeInTheDocument();
            expect(screen.getByTestId('marker')).toBeInTheDocument();
            expect(screen.queryByTestId('rectangle')).not.toBeInTheDocument();
        });

        it('should render only local rectangles for mixed global and local box coverage', () => {
            render(
                <LocationSection
                    geoLocations={[
                        {
                            id: 1,
                            place: 'World',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: -180,
                            east_bound_longitude: 180,
                            south_bound_latitude: -90,
                            north_bound_latitude: 90,
                            polygon_points: null,
                            geo_type: 'box',
                        },
                        {
                            id: 2,
                            place: 'Germany',
                            point_longitude: null,
                            point_latitude: null,
                            west_bound_longitude: 5.87,
                            east_bound_longitude: 15.04,
                            south_bound_latitude: 47.27,
                            north_bound_latitude: 55.06,
                            polygon_points: null,
                            geo_type: 'box',
                        },
                    ]}
                />,
            );

            const rectangles = screen.getAllByTestId('rectangle');
            expect(rectangles).toHaveLength(1);
            expect(JSON.parse(rectangles[0].dataset.bounds || '[]')).toEqual([
                [47.27, 5.87],
                [55.06, 15.04],
            ]);
        });
    });

    describe('TileLayer', () => {
        const sampleGeoLocations = [
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
                geo_type: null,
            },
        ];

        it('should render TileLayer with OpenStreetMap', () => {
            render(<LocationSection geoLocations={sampleGeoLocations} />);
            expect(screen.getByTestId('tile-layer')).toBeInTheDocument();
        });

        it('should use OpenStreetMap tiles by default (light mode)', () => {
            render(<LocationSection geoLocations={sampleGeoLocations} />);
            const tile = screen.getByTestId('tile-layer');
            expect(tile.dataset.url).toContain('openstreetmap.org');
        });

        it('should use CartoDB dark tiles when isDark is true', () => {
            render(<LocationSection geoLocations={sampleGeoLocations} isDark={true} />);
            const tile = screen.getByTestId('tile-layer');
            expect(tile.dataset.url).toContain('basemaps.cartocdn.com');
            expect(tile.dataset.url).toContain('dark_all');
        });

        it('should use OpenStreetMap tiles when isDark is false', () => {
            render(<LocationSection geoLocations={sampleGeoLocations} isDark={false} />);
            const tile = screen.getByTestId('tile-layer');
            expect(tile.dataset.url).toContain('openstreetmap.org');
        });
    });

    describe('fullscreen support', () => {
        it('should gracefully handle when fullscreen API is not available', () => {
            // The FullscreenControl checks for requestFullscreen support
            // and only renders when it's available. In jsdom, it may or may not be mocked.
            // The component should not throw an error regardless.
            expect(() =>
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
                                geo_type: null,
                            },
                        ]}
                    />,
                ),
            ).not.toThrow();
        });
    });
});
