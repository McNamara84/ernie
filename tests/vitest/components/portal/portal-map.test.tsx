import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalFilters, PortalMapClusterMembersResponse, PortalMapFeature, PortalMapResponse } from '@/types/portal';

const mapEvents = vi.hoisted(() => new Map<string, () => void>());
const mapQueryState = vi.hoisted(() => ({
    result: {
        data: undefined as PortalMapResponse | undefined,
        isLoading: false,
        isFetching: false,
        isError: false,
        refetch: vi.fn(),
    },
}));
const usePortalMapDataMock = vi.hoisted(() => vi.fn(() => mapQueryState.result));
const clusterMembersQueryState = vi.hoisted(() => ({
    result: {
        data: undefined as PortalMapClusterMembersResponse | undefined,
        isLoading: false,
        isError: false,
    },
}));
const usePortalMapClusterMembersMock = vi.hoisted(() => vi.fn(() => clusterMembersQueryState.result));
const clusterLayerMock = vi.hoisted(() =>
    vi.fn(
        ({
            features,
            maxZoom,
            interactive,
            onExpandCluster,
        }: {
            features: PortalMapFeature[];
            maxZoom: number;
            interactive?: boolean;
            onExpandCluster?: (feature: Extract<PortalMapFeature, { kind: 'cluster' }>) => void;
        }) => {
            const cluster = features.find((feature) => feature.kind === 'cluster');

            return (
                <button
                    type="button"
                    data-testid="cluster-layer"
                    data-max-zoom={maxZoom}
                    data-interactive={String(interactive)}
                    onClick={() => cluster?.kind === 'cluster' && onExpandCluster?.(cluster)}
                >
                    {features.length}
                </button>
            );
        },
    ),
);

const mockMap = vi.hoisted(() => ({
    fitBounds: vi.fn(),
    setView: vi.fn(),
    invalidateSize: vi.fn(),
    getZoom: vi.fn(() => 4),
    getCenter: vi.fn(() => ({ lat: 0, lng: 180 })),
    on: vi.fn((event: string, callback: () => void) => mapEvents.set(event, callback)),
    off: vi.fn((event: string) => mapEvents.delete(event)),
    getBounds: vi.fn(() => ({
        getNorth: () => 53,
        getSouth: () => 51,
        getEast: () => 14,
        getWest: () => 12,
    })),
    getContainer: vi.fn(() => {
        const element = document.createElement('div');
        Object.defineProperty(element, 'clientWidth', { value: 800 });
        Object.defineProperty(element, 'clientHeight', { value: 600 });
        return element;
    }),
}));

vi.mock('@/hooks/use-portal-map-data', () => ({ usePortalMapData: usePortalMapDataMock }));
vi.mock('@/hooks/use-portal-map-cluster-members', () => ({ usePortalMapClusterMembers: usePortalMapClusterMembersMock }));
vi.mock('@/components/portal/PortalMapCluster', () => ({ ClusterLayer: clusterLayerMock }));
vi.mock('@/components/portal/PortalMapClusterMembers', () => ({
    ClusterMembersLayer: ({ members, total }: { members: unknown[]; total: number }) => (
        <div data-testid="cluster-members-layer">{`${members.length}/${total}`}</div>
    ),
    ClusterMembersPanel: ({
        result,
        isLoading,
        isError,
        onClose,
        onPageChange,
    }: {
        result: PortalMapClusterMembersResponse | undefined;
        isLoading: boolean;
        isError: boolean;
        onClose: () => void;
        onPageChange: (page: number) => void;
    }) => (
        <div data-testid="cluster-members-panel">
            {isLoading ? 'loading' : isError ? 'error' : result?.clusterId}
            <button type="button" onClick={() => onPageChange(2)}>
                page 2
            </button>
            <button type="button" onClick={onClose}>
                close
            </button>
        </div>
    ),
}));
vi.mock('@/components/portal/PortalMapLegend', () => ({
    PortalMapLegend: ({ features }: { features: unknown[] }) => <div data-testid="map-legend">{features.length}</div>,
}));
vi.mock('leaflet/dist/leaflet.css', () => ({}));
vi.mock('leaflet', () => ({
    default: {
        latLngBounds: vi.fn(() => ({
            isValid: () => true,
            getNorthEast: () => ({ equals: () => false }),
            getSouthWest: () => ({}),
            getCenter: () => ({ lat: 52, lng: 13 }),
        })),
    },
}));
vi.mock('react-leaflet', () => ({
    MapContainer: ({ children, maxZoom, zoom }: { children: React.ReactNode; maxZoom: number; zoom: number }) => (
        <div data-testid="leaflet-map" data-max-zoom={maxZoom} data-initial-zoom={zoom}>
            {children}
        </div>
    ),
    TileLayer: ({ maxZoom, maxNativeZoom }: { maxZoom: number; maxNativeZoom: number }) => (
        <div data-testid="tile-layer" data-max-zoom={maxZoom} data-max-native-zoom={maxNativeZoom} />
    ),
    Popup: ({ children }: { children: React.ReactNode }) => <div data-testid="popup">{children}</div>,
    Rectangle: ({ children, bounds, pathOptions }: { children: React.ReactNode; bounds: unknown; pathOptions: unknown }) => (
        <div data-testid="rectangle" data-bounds={JSON.stringify(bounds)} data-path-options={JSON.stringify(pathOptions)}>
            {children}
        </div>
    ),
    Polygon: ({ children, positions, pathOptions }: { children: React.ReactNode; positions: unknown; pathOptions: unknown }) => (
        <div data-testid="polygon" data-positions={JSON.stringify(positions)} data-path-options={JSON.stringify(pathOptions)}>
            {children}
        </div>
    ),
    Polyline: ({ children, positions, pathOptions }: { children: React.ReactNode; positions: unknown; pathOptions: unknown }) => (
        <div data-testid="polyline" data-positions={JSON.stringify(positions)} data-path-options={JSON.stringify(pathOptions)}>
            {children}
        </div>
    ),
    useMap: () => mockMap,
}));

import { PortalMap } from '@/components/portal/PortalMap';

const filters: PortalFilters = {
    query: null,
    type: [],
    keywords: [],
    freeKeywords: [],
    thesaurusKeywords: [],
    sampleTypes: [],
    materials: [],
    classifications: [],
    geologicalAges: [],
    geologicalUnits: [],
    datacenter: [],
    bounds: null,
    temporal: null,
};

const response = (overrides: Partial<PortalMapResponse> = {}): PortalMapResponse => ({
    schemaVersion: 3,
    features: [],
    meta: {
        requestedZoom: 4,
        effectiveZoom: 4,
        visibleLocations: 0,
        returnedFeatures: 0,
        totalLocations: 0,
        extent: null,
        coarsened: false,
    },
    ...overrides,
});

describe('PortalMap', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mapEvents.clear();
        mockMap.getCenter.mockReturnValue({ lat: 0, lng: 180 });
        mapQueryState.result = {
            data: undefined,
            isLoading: false,
            isFetching: false,
            isError: false,
            refetch: vi.fn(),
        };
        clusterMembersQueryState.result = {
            data: undefined,
            isLoading: false,
            isError: false,
        };
    });

    it('requests map data only after Leaflet reports a visible viewport', async () => {
        render(<PortalMap filters={filters} maxZoom={18} />);

        await waitFor(() =>
            expect(usePortalMapDataMock).toHaveBeenCalledWith(
                filters,
                expect.objectContaining({ north: 53, south: 51, east: 14, west: 12, width: 800, height: 600, zoom: 4 }),
                false,
                '/doi-search',
                18,
            ),
        );
    });

    it('uses the configured zoom limit for the map, clusters, and requests', async () => {
        render(<PortalMap filters={filters} maxZoom={7} />);

        expect(screen.getAllByTestId('leaflet-map')[0]).toHaveAttribute('data-max-zoom', '7');
        expect(screen.getAllByTestId('tile-layer')[0]).toHaveAttribute('data-max-zoom', '7');
        expect(screen.getAllByTestId('cluster-layer')[0]).toHaveAttribute('data-max-zoom', '7');
        await waitFor(() =>
            expect(usePortalMapDataMock).toHaveBeenCalledWith(filters, expect.objectContaining({ zoom: 4 }), false, '/doi-search', 7),
        );
    });

    it('passes bounded server features to marker and legend layers', () => {
        mapQueryState.result.data = response({
            features: [
                {
                    kind: 'cluster',
                    id: 'z2:1:1',
                    position: { lat: 52, lng: 13 },
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                    count: 25,
                    resourceTypeCounts: { dataset: 25 },
                },
            ],
        });

        render(<PortalMap filters={filters} maxZoom={18} />);

        expect(screen.getAllByTestId('cluster-layer')[0]).toHaveTextContent('1');
        expect(screen.getAllByTestId('map-legend')[0]).toHaveTextContent('1');
    });

    it.each([
        ['box', 'rectangle'],
        ['polygon', 'polygon'],
        ['line', 'polyline'],
    ] as const)('renders a returned %s detail geometry', (geometryType, testId) => {
        const geometry =
            geometryType === 'box'
                ? { type: 'box' as const, north: 53, south: 51, east: 14, west: 12 }
                : {
                      type: geometryType,
                      points: [
                          { latitude: 51, longitude: 12 },
                          { latitude: 53, longitude: 14 },
                          { latitude: 52, longitude: 13 },
                      ],
                  };
        mapQueryState.result.data = response({
            features: [
                {
                    kind: 'resource',
                    id: '1',
                    position: { lat: 52, lng: 13 },
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                    geometry,
                    resource: {
                        id: 1,
                        identifier: '10.1/test',
                        title: 'Mapped resource',
                        creators: [],
                        resourceType: { slug: 'dataset', name: 'Dataset' },
                        landingPageUrl: '/test',
                    },
                },
            ],
        });

        render(<PortalMap filters={filters} maxZoom={18} />);

        const renderedGeometry = screen.getAllByTestId(testId)[0];
        expect(renderedGeometry).toBeInTheDocument();
        expect(JSON.parse(renderedGeometry.getAttribute('data-path-options') ?? '{}')).toMatchObject({
            color: '#0C2A63',
            ...(geometryType === 'line' ? {} : { fillColor: '#0C2A63' }),
        });
        expect(screen.getAllByText('Mapped resource')[0]).toBeInTheDocument();
    });

    it.each([
        ['box', 'rectangle'],
        ['polygon', 'polygon'],
        ['line', 'polyline'],
    ] as const)('colors a returned IGSN %s geometry by material', (geometryType, testId) => {
        const geometry =
            geometryType === 'box'
                ? { type: 'box' as const, north: 53, south: 51, east: 14, west: 12 }
                : {
                      type: geometryType,
                      points: [
                          { latitude: 51, longitude: 12 },
                          { latitude: 53, longitude: 14 },
                          { latitude: 52, longitude: 13 },
                      ],
                  };
        mapQueryState.result.data = response({
            schemaVersion: 2,
            features: [
                {
                    kind: 'resource',
                    id: 'igsn-shape',
                    position: { lat: 52, lng: 13 },
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                    geometry,
                    resource: {
                        id: 2,
                        identifier: '10.60510/test',
                        title: 'Mapped IGSN shape',
                        creators: [],
                        resourceType: { slug: 'physical-object', name: 'Physical Object' },
                        presentation: { dimension: 'material', key: 'rock', label: 'Rock', status: 'value' },
                        igsn: { sampleType: 'Core', material: 'Rock', materialLabel: 'Rock' },
                        landingPageUrl: '/igsn-shape',
                    },
                },
            ],
        });

        render(<PortalMap filters={filters} maxZoom={18} basePath="/igsn-search" />);

        const renderedGeometry = screen.getAllByTestId(testId)[0];
        expect(JSON.parse(renderedGeometry.getAttribute('data-path-options') ?? '{}')).toMatchObject({
            color: '#6F4E37',
            ...(geometryType === 'line' ? {} : { fillColor: '#6F4E37' }),
        });
    });

    it('renders a wrapped box as the short interval across the antimeridian', () => {
        mapQueryState.result.data = response({
            features: [
                {
                    kind: 'resource',
                    id: 'wrapped-box',
                    position: { lat: 0, lng: 180 },
                    bounds: { north: 10, south: -10, west: 170, east: -170 },
                    geometry: { type: 'box', north: 10, south: -10, west: 170, east: -170 },
                    resource: {
                        id: 1,
                        identifier: '10.1/wrapped-box',
                        title: 'Wrapped box',
                        creators: [],
                        resourceType: { slug: 'dataset', name: 'Dataset' },
                        landingPageUrl: '/wrapped-box',
                    },
                },
            ],
        });

        render(<PortalMap filters={filters} maxZoom={18} />);

        expect(screen.getAllByTestId('rectangle')[0]).toHaveAttribute(
            'data-bounds',
            JSON.stringify([
                [-10, 170],
                [10, 190],
            ]),
        );
    });

    it('unwraps dateline-crossing polygon paths onto one Leaflet world copy', () => {
        mapQueryState.result.data = response({
            features: [
                {
                    kind: 'resource',
                    id: 'wrapped-polygon',
                    position: { lat: 0, lng: 180 },
                    bounds: { north: 10, south: -10, west: 179, east: -179 },
                    geometry: {
                        type: 'polygon',
                        points: [
                            { latitude: -10, longitude: 179 },
                            { latitude: 10, longitude: -179 },
                            { latitude: 0, longitude: 178 },
                        ],
                    },
                    resource: {
                        id: 1,
                        identifier: '10.1/wrapped-polygon',
                        title: 'Wrapped polygon',
                        creators: [],
                        resourceType: { slug: 'dataset', name: 'Dataset' },
                        landingPageUrl: '/wrapped-polygon',
                    },
                },
            ],
        });

        render(<PortalMap filters={filters} maxZoom={18} />);

        expect(screen.getAllByTestId('polygon')[0]).toHaveAttribute(
            'data-positions',
            JSON.stringify([
                [-10, 179],
                [10, 181],
                [0, 178],
            ]),
        );
    });

    it('reports move-end bounds to the spatial filter while always refreshing technical map data', async () => {
        const onViewportChange = vi.fn();
        render(<PortalMap filters={filters} maxZoom={18} geoFilterEnabled onViewportChange={onViewportChange} />);

        await waitFor(() => expect(mapEvents.has('moveend')).toBe(true));
        act(() => mapEvents.get('moveend')?.());

        expect(onViewportChange).toHaveBeenCalledWith({ north: 53, south: 51, east: 14, west: 12 });
        expect(usePortalMapDataMock).toHaveBeenLastCalledWith(
            filters,
            expect.objectContaining({ width: 800, height: 600 }),
            false,
            '/doi-search',
            18,
        );
    });

    it('debounces resize-driven technical viewport requests', () => {
        vi.useFakeTimers();

        try {
            render(<PortalMap filters={filters} maxZoom={18} hideHeader />);
            act(() => vi.runOnlyPendingTimers());
            usePortalMapDataMock.mockClear();

            act(() => {
                mapEvents.get('resize')?.();
                mapEvents.get('resize')?.();
                mapEvents.get('resize')?.();
            });

            expect(usePortalMapDataMock).not.toHaveBeenCalled();
            act(() => vi.advanceTimersByTime(249));
            expect(usePortalMapDataMock).not.toHaveBeenCalled();

            act(() => vi.advanceTimersByTime(1));
            expect(usePortalMapDataMock).toHaveBeenCalledTimes(1);
            expect(usePortalMapDataMock).toHaveBeenLastCalledWith(
                filters,
                expect.objectContaining({ north: 53, south: 51, east: 14, west: 12, width: 800, height: 600, zoom: 4 }),
                false,
                '/doi-search',
                18,
            );
        } finally {
            vi.useRealTimers();
        }
    });

    it('shows loading, empty, and recoverable error feedback', () => {
        mapQueryState.result.data = response();
        mapQueryState.result.isFetching = true;
        const { rerender } = render(<PortalMap filters={filters} maxZoom={18} />);
        expect(screen.getAllByRole('status')[0]).toHaveTextContent('Updating map');
        expect(screen.getAllByTestId('leaflet-map')[0].parentElement).toHaveAttribute('aria-busy', 'true');
        expect(screen.getAllByTestId('cluster-layer')[0]).toHaveAttribute('data-interactive', 'false');
        expect(screen.getAllByText(/No geographic data/)[0]).toBeInTheDocument();

        mapQueryState.result.isFetching = false;
        mapQueryState.result.isError = true;
        rerender(<PortalMap filters={filters} maxZoom={18} />);
        const retryButton = screen.getAllByRole('button', { name: /try again/i })[0];
        expect(retryButton).toHaveAttribute('data-slot', 'button');
        fireEvent.click(retryButton);
        expect(mapQueryState.result.refetch).toHaveBeenCalled();
    });

    it('loads terminal cluster members for the viewport and supports paging and closing the result panel', async () => {
        const cluster: Extract<PortalMapFeature, { kind: 'cluster' }> = {
            kind: 'cluster',
            id: 'z18:73900:43000',
            position: { lat: 52, lng: 13 },
            bounds: { north: 52, south: 52, east: 13, west: 13 },
            navigationBounds: { north: 52, south: 52, east: 13, west: 13 },
            count: 2,
            resourceTypeCounts: { dataset: 2 },
        };
        mapQueryState.result.data = response({ features: [cluster] });
        clusterMembersQueryState.result.data = {
            schemaVersion: 1,
            clusterId: cluster.id,
            total: 2,
            members: [],
            pagination: { currentPage: 1, lastPage: 1, perPage: 50 },
        };

        render(<PortalMap filters={filters} maxZoom={18} hideHeader />);
        await waitFor(() =>
            expect(usePortalMapDataMock).toHaveBeenCalledWith(filters, expect.objectContaining({ zoom: 4 }), true, '/doi-search', 18),
        );

        fireEvent.click(screen.getByTestId('cluster-layer'));

        await waitFor(() =>
            expect(usePortalMapClusterMembersMock).toHaveBeenLastCalledWith(
                filters,
                expect.objectContaining({ north: 53, south: 51, east: 14, west: 12, zoom: 4 }),
                cluster.id,
                1,
                '/doi-search',
                18,
            ),
        );
        expect(screen.getByTestId('cluster-members-panel')).toHaveTextContent(cluster.id);
        expect(screen.getByTestId('cluster-members-layer')).toHaveTextContent('0/2');

        fireEvent.click(screen.getByRole('button', { name: 'page 2' }));
        expect(usePortalMapClusterMembersMock).toHaveBeenLastCalledWith(filters, expect.anything(), cluster.id, 2, '/doi-search', 18);

        fireEvent.click(screen.getByRole('button', { name: 'close' }));
        expect(screen.queryByTestId('cluster-members-panel')).not.toBeInTheDocument();
        expect(usePortalMapClusterMembersMock).toHaveBeenLastCalledWith(filters, null, null, 1, '/doi-search', 18);
    });

    it('reports the total location count returned with an extent request', () => {
        const onLocationCountChange = vi.fn();
        mapQueryState.result.data = response({ meta: { ...response().meta, totalLocations: 35_638, visibleLocations: 120 } });

        render(<PortalMap filters={filters} maxZoom={18} onLocationCountChange={onLocationCountChange} />);

        expect(onLocationCountChange).toHaveBeenCalledWith(35_638);
        expect(screen.getAllByText(/35[.,]638 locations/)[0]).toBeInTheDocument();
    });
});
