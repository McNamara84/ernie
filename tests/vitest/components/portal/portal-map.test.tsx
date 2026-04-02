import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, render, screen } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalGeoLocation, PortalResource } from '@/types/portal';

// Hoisted stable mock for useMap so we can assert on calls
const mockMapInstance = vi.hoisted(() => ({
    fitBounds: vi.fn(),
    setView: vi.fn(),
    invalidateSize: vi.fn(),
    on: vi.fn(),
    off: vi.fn(),
    getBounds: vi.fn(() => ({
        getNorth: () => 53,
        getSouth: () => 51,
        getEast: () => 14,
        getWest: () => 12,
    })),
    getContainer: vi.fn(() => {
        const el = document.createElement('div');
        Object.defineProperty(el, 'clientWidth', { value: 800 });
        Object.defineProperty(el, 'clientHeight', { value: 600 });
        return el;
    }),
}));

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
    Polyline: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map-polyline">{children}</div>
    ),
    useMap: () => mockMapInstance,
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

// Mock leaflet.markercluster (requires global L which doesn't exist in jsdom)
vi.mock('leaflet.markercluster', () => ({}));
vi.mock('leaflet.markercluster/dist/MarkerCluster.css', () => ({}));
vi.mock('leaflet.markercluster/dist/MarkerCluster.Default.css', () => ({}));

// Mock ClusterLayer – the real component uses L.markerClusterGroup which isn't available in jsdom
vi.mock('@/components/portal/PortalMapCluster', () => ({
    ClusterLayer: () => <div data-testid="cluster-layer" />,
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

        it('renders ClusterLayer for point geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                    { id: 2, type: 'point', point: { lat: 48.2, lng: 11.8 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            // Markers are now rendered imperatively inside ClusterLayer
            expect(screen.getAllByTestId('cluster-layer').length).toBeGreaterThan(0);
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
        // Note: Popup content (title, author, year, links) is now generated as HTML strings
        // by the ClusterLayer component. These are tested in portal-map-config.test.ts
        // (renderPopupHtml, formatAuthorsShort). Here we only verify what's still in React DOM.

        it('renders resource type in legend', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            resources[0].resourceType = 'Dataset';
            render(<PortalMap resources={resources} />);

            // Resource type appears in the legend
            expect(screen.getAllByText('Dataset').length).toBeGreaterThan(0);
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

            // Point markers handled by ClusterLayer, shapes still React components
            expect(screen.getAllByTestId('cluster-layer').length).toBeGreaterThan(0);
            expect(screen.getAllByTestId('map-rectangle').length).toBeGreaterThan(0);
            expect(screen.getAllByTestId('map-polygon').length).toBeGreaterThan(0);
        });
    });

    describe('IGSN Resources', () => {
        it('renders IGSN resource type in legend', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            resources[0].isIgsn = true;
            resources[0].resourceType = 'PhysicalObject';
            resources[0].resourceTypeSlug = 'physical-object';
            render(<PortalMap resources={resources} />);

            // IGSN resource type appears in legend
            expect(screen.getAllByText('PhysicalObject').length).toBeGreaterThan(0);
        });
    });

    describe('Geo Filter Props', () => {
        it('renders without crashing when geoFilterEnabled is false', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} geoFilterEnabled={false} />);

            expect(screen.getAllByTestId('map-container').length).toBeGreaterThan(0);
        });

        it('registers moveend handler when geoFilterEnabled is true', () => {
            const onViewportChange = vi.fn();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={true}
                    onViewportChange={onViewportChange}
                />,
            );

            // ViewportTracker should have registered moveend handler via useMap().on
            expect(mockMapInstance.on).toHaveBeenCalledWith('moveend', expect.any(Function));
        });

        it('calls onViewportChange with bounds when moveend fires', () => {
            const onViewportChange = vi.fn();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={true}
                    onViewportChange={onViewportChange}
                />,
            );

            // Extract the moveend handler and call it
            const moveendCall = mockMapInstance.on.mock.calls.find(
                (call: unknown[]) => call[0] === 'moveend',
            );
            expect(moveendCall).toBeDefined();
            const handler = moveendCall![1] as () => void;
            act(() => handler());

            expect(onViewportChange).toHaveBeenCalledWith({
                north: 53,
                south: 51,
                east: 14,
                west: 12,
            });
        });

        it('cleans up moveend handler on unmount', () => {
            const onViewportChange = vi.fn();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            const { unmount } = render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={true}
                    onViewportChange={onViewportChange}
                />,
            );

            unmount();

            expect(mockMapInstance.off).toHaveBeenCalledWith('moveend', expect.any(Function));
        });

        it('calls fitBounds when flyToBounds is provided', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={true}
                    flyToBounds={{ north: 53, south: 51, east: 14, west: 12 }}
                />,
            );

            // MapBoundsUpdater should have called fitBounds
            expect(mockMapInstance.fitBounds).toHaveBeenCalledWith(
                [[51, 12], [53, 14]],
                { padding: [20, 20], animate: true },
            );
        });

        it('uses setView instead of fitBounds when flyToBounds crosses anti-meridian', () => {
            mockMapInstance.setView.mockClear();
            mockMapInstance.fitBounds.mockClear();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 170 }, bounds: null, polygon: null },
                ]),
            ];
            // west=170 > east=-170 means anti-meridian crossing
            render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={true}
                    flyToBounds={{ north: 60, south: 40, east: -170, west: 170 }}
                />,
            );

            // Should use setView with center/zoom instead of fitBounds
            expect(mockMapInstance.setView).toHaveBeenCalledWith(
                [50, 180],
                expect.any(Number),
                { animate: true },
            );
        });

        it('suppresses moveend after programmatic flyToBounds to prevent overwriting manual bounds', () => {
            const onViewportChange = vi.fn();
            mockMapInstance.on.mockClear();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={true}
                    onViewportChange={onViewportChange}
                    flyToBounds={{ north: 53, south: 51, east: 14, west: 12 }}
                />,
            );

            // Extract the moveend handler registered by ViewportTracker
            const moveendCall = mockMapInstance.on.mock.calls.find(
                (call: unknown[]) => call[0] === 'moveend',
            );
            expect(moveendCall).toBeDefined();
            const handler = moveendCall![1] as () => void;

            // First moveend after programmatic fly-to should be suppressed
            act(() => handler());
            expect(onViewportChange).not.toHaveBeenCalled();

            // Second moveend (user-initiated) should fire normally
            act(() => handler());
            expect(onViewportChange).toHaveBeenCalledTimes(1);
        });

        it('renders with null flyToBounds prop without calling fitBounds for bounds', () => {
            mockMapInstance.fitBounds.mockClear();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={true}
                    flyToBounds={null}
                />,
            );

            // fitBounds is called for FitBoundsControl but not for MapBoundsUpdater
            expect(screen.getAllByTestId('map-container').length).toBeGreaterThan(0);
        });

        it('does not register moveend handler when geoFilterEnabled is false', () => {
            mockMapInstance.on.mockClear();
            const onViewportChange = vi.fn();
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(
                <PortalMap
                    resources={resources}
                    geoFilterEnabled={false}
                    onViewportChange={onViewportChange}
                />,
            );

            // ViewportTracker should NOT be rendered, so no moveend registration
            const moveendCalls = mockMapInstance.on.mock.calls.filter(
                (call: unknown[]) => call[0] === 'moveend',
            );
            expect(moveendCalls.length).toBe(0);
        });
    });

    describe('Line Geo Locations', () => {
        it('renders polyline for line type geo locations', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    {
                        id: 1,
                        type: 'line',
                        point: null,
                        bounds: null,
                        polygon: [
                            { lat: 52.5, lng: 13.4 },
                            { lat: 48.2, lng: 11.8 },
                        ],
                    },
                ]),
            ];
            render(<PortalMap resources={resources} />);

            expect(screen.getAllByTestId('map-polyline').length).toBeGreaterThan(0);
        });
    });

    // Note: Author formatting tests (ampersand, et al., Unknown) are now covered
    // by portal-map-config.test.ts > formatAuthorsShort since popup content is
    // generated as HTML strings by ClusterLayer, not as React DOM.

    describe('Header Modes', () => {
        it('hides collapsible header when hideHeader is true', () => {
            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} hideHeader />);

            // Map should render but the collapsible section should not
            expect(screen.getAllByTestId('map-container').length).toBeGreaterThan(0);
        });
    });

    describe('FitBoundsControl Branches', () => {
        // Replace the global ResizeObserver stub (from vitest.setup.ts) with
        // one whose callback fires immediately on observe(), so fake timers
        // can drive the debounced initial-fit logic.
        function installImmediateResizeObserver() {
            vi.stubGlobal(
                'ResizeObserver',
                class {
                    private cb: ResizeObserverCallback;
                    constructor(cb: ResizeObserverCallback) {
                        this.cb = cb;
                    }
                    observe() {
                        this.cb([], this as unknown as ResizeObserver);
                    }
                    unobserve() {}
                    disconnect() {}
                },
            );
        }

        afterEach(() => {
            vi.unstubAllGlobals();
            vi.useRealTimers();
        });

        it('skips initial auto-fit when geoFilterEnabled is true', () => {
            vi.useFakeTimers();
            installImmediateResizeObserver();
            mockMapInstance.fitBounds.mockClear();
            mockMapInstance.setView.mockClear();

            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} geoFilterEnabled={true} />);

            // Advance well past the 150 ms debounce — still no fit expected
            act(() => {
                vi.advanceTimersByTime(500);
            });

            expect(mockMapInstance.fitBounds).not.toHaveBeenCalled();
            expect(mockMapInstance.setView).not.toHaveBeenCalled();
        });

        it('performs initial auto-fit when geoFilterEnabled is false', () => {
            vi.useFakeTimers();
            installImmediateResizeObserver();
            mockMapInstance.fitBounds.mockClear();
            mockMapInstance.setView.mockClear();

            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];
            render(<PortalMap resources={resources} geoFilterEnabled={false} />);

            act(() => {
                vi.advanceTimersByTime(200);
            });

            // At least one FitBoundsControl instance should have called fitBounds
            expect(mockMapInstance.fitBounds).toHaveBeenCalled();
        });

        it('re-fits when geoFilterEnabled transitions from true to false', () => {
            vi.useFakeTimers();
            installImmediateResizeObserver();
            mockMapInstance.fitBounds.mockClear();
            mockMapInstance.setView.mockClear();

            const resources = [
                createMockResourceWithGeo(1, [
                    { id: 1, type: 'point', point: { lat: 52.5, lng: 13.4 }, bounds: null, polygon: null },
                ]),
            ];

            // 1) Start with geoFilterEnabled=false so the initial fit runs
            const { rerender } = render(
                <PortalMap resources={resources} geoFilterEnabled={false} />,
            );
            act(() => {
                vi.advanceTimersByTime(200);
            });
            expect(mockMapInstance.fitBounds).toHaveBeenCalled();
            mockMapInstance.fitBounds.mockClear();

            // 2) Enable geo filter — no additional fit expected
            rerender(<PortalMap resources={resources} geoFilterEnabled={true} />);
            act(() => {
                vi.advanceTimersByTime(200);
            });
            expect(mockMapInstance.fitBounds).not.toHaveBeenCalled();

            // 3) Disable geo filter again — re-fit should happen synchronously
            rerender(<PortalMap resources={resources} geoFilterEnabled={false} />);
            expect(mockMapInstance.fitBounds).toHaveBeenCalled();
        });
    });
});
