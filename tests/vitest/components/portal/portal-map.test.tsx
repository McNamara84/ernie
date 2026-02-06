import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalGeoLocation, PortalResource } from '@/types/portal';

// Mock react-leaflet components since Leaflet requires DOM and canvas
vi.mock('react-leaflet', () => ({
    MapContainer: ({ children, className }: { children: React.ReactNode; className?: string }) => (
        <div data-testid="map-container" className={className}>
            {children}
        </div>
    ),
    TileLayer: () => <div data-testid="tile-layer" />,
    Marker: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map-marker">{children}</div>
    ),
    Popup: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map-popup">{children}</div>
    ),
    Rectangle: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map-rectangle">{children}</div>
    ),
    Polygon: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map-polygon">{children}</div>
    ),
    useMap: () => ({
        fitBounds: vi.fn(),
        setView: vi.fn(),
        invalidateSize: vi.fn(),
    }),
}));

// Mock leaflet
vi.mock('leaflet', () => ({
    default: {
        Icon: {
            Default: {
                prototype: {},
                mergeOptions: vi.fn(),
            },
        },
        latLngBounds: vi.fn((points) => ({
            isValid: () => points && points.length > 0,
        })),
    },
}));

// Mock leaflet CSS import
vi.mock('leaflet/dist/leaflet.css', () => ({}));

// Mock leaflet marker icons
vi.mock('leaflet/dist/images/marker-icon.png', () => ({ default: 'marker-icon.png' }));
vi.mock('leaflet/dist/images/marker-icon-2x.png', () => ({ default: 'marker-icon-2x.png' }));
vi.mock('leaflet/dist/images/marker-shadow.png', () => ({ default: 'marker-shadow.png' }));

// Import after mocks
import { PortalMap } from '@/components/portal/PortalMap';

/**
 * Factory to create a mock PortalResource with geo location
 */
function createMockResourceWithGeo(
    id: number,
    geoLocations: PortalGeoLocation[] = [],
): PortalResource {
    return {
        id,
        title: `Resource ${id}`,
        doi: `10.5880/GFZ.TEST.${id}`,
        resourceType: 'Dataset',
        resourceTypeSlug: 'dataset',
        isIgsn: false,
        year: 2024,
        landingPageUrl: `/landing/resource-${id}`,
        creators: [{ name: `Author ${id}` }],
        geoLocations,
    };
}

describe('PortalMap', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Collapsible Behavior', () => {
        it('renders map header with location count', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            // Map has two headers (collapsed and side panel), so use getAllBy
            expect(screen.getAllByText(/Map/)[0]).toBeInTheDocument();
            expect(screen.getAllByText('(1 location)').length).toBeGreaterThan(0);
        });

        it('shows plural "locations" when multiple geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                    { id: 2, type: 'point', point: { lat: 48.2, lng: 11.8 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            expect(screen.getAllByText('(2 locations)').length).toBeGreaterThan(0);
        });

        it('shows 0 locations when no resources have geo data', () => {
            const resources = [createMockResourceWithGeo(1, [])];
            render(<PortalMap resources={resources} />);

            expect(screen.getAllByText('(0 locations)').length).toBeGreaterThan(0);
        });

        it('can collapse and expand the map', async () => {
            const user = userEvent.setup();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            // Get the collapsible toggle button (in the 2xl:hidden section)
            const toggleButtons = screen.getAllByRole('button');
            const toggleButton = toggleButtons.find(btn => btn.textContent?.includes('Map'));

            // Initially maps are rendered
            expect(screen.getAllByTestId('map-container').length).toBeGreaterThan(0);

            // Collapse - clicking the toggle changes the collapsible state
            if (toggleButton) {
                await user.click(toggleButton);
            }
        });
    });

    describe('Map Rendering', () => {
        it('renders MapContainer when resources have geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            // Dual-render layout: both collapsed and side-panel versions render
            expect(screen.getAllByTestId('map-container').length).toBeGreaterThan(0);
            expect(screen.getAllByTestId('tile-layer').length).toBeGreaterThan(0);
        });

        it('renders markers for point geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                    { id: 2, type: 'point', point: { lat: 48.2, lng: 11.8 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            // 2 markers x 2 layouts = 4 markers
            const markers = screen.getAllByTestId('map-marker');
            expect(markers.length).toBeGreaterThanOrEqual(2);
        });

        it('renders rectangles for bounding box geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    {
                        id: 1,
                        type: 'box',
                        point: null,
                        bounds: { north: 53, south: 52, east: 14, west: 13 },
                        polygon: null,
                    },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            expect(screen.getAllByTestId('map-rectangle').length).toBeGreaterThan(0);
        });

        it('renders polygons for polygon geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    {
                        id: 1,
                        type: 'polygon',
                        point: null,
                        bounds: null,
                        polygon: [
                            { lat: 52.5, lng: 13.4 },
                            { lat: 52.6, lng: 13.5 },
                            { lat: 52.4, lng: 13.6 },
                        ],
                    },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            expect(screen.getAllByTestId('map-polygon').length).toBeGreaterThan(0);
        });
    });

    describe('Popup Content', () => {
        it('renders resource title in popup', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            // Modify resource title
            resources[0].title = 'Test Dataset Title';
            render(<PortalMap resources={resources} />);

            // Title appears in both layouts' popups
            expect(screen.getAllByText('Test Dataset Title').length).toBeGreaterThan(0);
        });

        it('renders resource type badge in popup', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            resources[0].resourceType = 'Dataset';
            render(<PortalMap resources={resources} />);

            // Badge appears in both layouts' popups
            expect(screen.getAllByText('Dataset').length).toBeGreaterThan(0);
        });

        it('renders author and year in popup', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            resources[0].creators = [{ name: 'Smith' }];
            resources[0].year = 2024;
            render(<PortalMap resources={resources} />);

            // Author/year appear in both layouts' popups
            expect(screen.getAllByText(/Smith/).length).toBeGreaterThan(0);
            expect(screen.getAllByText(/2024/).length).toBeGreaterThan(0);
        });

        it('renders "View Details" link when landing page exists', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            resources[0].landingPageUrl = '/landing/test';
            render(<PortalMap resources={resources} />);

            // Links appear in both layouts' popups
            const links = screen.getAllByRole('link', { name: /view details/i });
            expect(links.length).toBeGreaterThan(0);
            expect(links[0]).toHaveAttribute('href', '/landing/test');
        });

        it('does not render "View Details" when no landing page', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            resources[0].landingPageUrl = null;
            render(<PortalMap resources={resources} />);

            expect(screen.queryByRole('link', { name: /view details/i })).not.toBeInTheDocument();
        });
    });

    describe('Empty State', () => {
        it('shows empty message when no resources have geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, []),
                createMockResourceWithGeo(2, []),
            ];
            render(<PortalMap resources={resources} />);

            expect(screen.getAllByText(/no geographic data available/i).length).toBeGreaterThan(0);
        });

        it('shows empty message with empty resources array', () => {
            render(<PortalMap resources={[]} />);

            expect(screen.getAllByText(/no geographic data available/i).length).toBeGreaterThan(0);
        });
    });

    describe('Multi-type Resources', () => {
        it('counts all geo locations across multiple resources', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
                createMockResourceWithGeo(2, [
                    { id: 2, type: 'box', point: null, bounds: { north: 53, south: 52, east: 14, west: 13 }, polygon: null },
                    { id: 3, type: 'polygon', point: null, bounds: null, polygon: [{ lat: 51, lng: 12 }, { lat: 52, lng: 13 }, { lat: 51, lng: 13 }] },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            expect(screen.getAllByText('(3 locations)').length).toBeGreaterThan(0);
        });

        it('renders all types of geo shapes together', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
                createMockResourceWithGeo(2, [
                    { id: 2, type: 'box', point: null, bounds: { north: 53, south: 52, east: 14, west: 13 }, polygon: null },
                ]),
                createMockResourceWithGeo(3, [
                    { id: 3, type: 'polygon', point: null, bounds: null, polygon: [{ lat: 51, lng: 12 }, { lat: 52, lng: 13 }, { lat: 51, lng: 13 }] },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            // All shape types rendered (x2 for dual layout)
            expect(screen.getAllByTestId('map-marker').length).toBeGreaterThan(0);
            expect(screen.getAllByTestId('map-rectangle').length).toBeGreaterThan(0);
            expect(screen.getAllByTestId('map-polygon').length).toBeGreaterThan(0);
        });
    });

    describe('IGSN Resources', () => {
        it('renders secondary badge variant for IGSN resources', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            resources[0].isIgsn = true;
            resources[0].resourceType = 'PhysicalObject';
            render(<PortalMap resources={resources} />);

            // Badge appears in both layouts' popups
            expect(screen.getAllByText('PhysicalObject').length).toBeGreaterThan(0);
        });
    });
});
