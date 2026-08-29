import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalFilters, PortalMapResponse } from '@/types/portal';

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
const clusterLayerMock = vi.hoisted(() => vi.fn(({ features }: { features: unknown[] }) => <div data-testid="cluster-layer">{features.length}</div>));

const mockMap = vi.hoisted(() => ({
    fitBounds: vi.fn(),
    setView: vi.fn(),
    invalidateSize: vi.fn(),
    getZoom: vi.fn(() => 4),
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
vi.mock('@/components/portal/PortalMapCluster', () => ({ ClusterLayer: clusterLayerMock }));
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
    MapContainer: ({ children }: { children: React.ReactNode }) => <div data-testid="leaflet-map">{children}</div>,
    TileLayer: () => <div data-testid="tile-layer" />,
    Popup: ({ children }: { children: React.ReactNode }) => <div data-testid="popup">{children}</div>,
    Rectangle: ({ children }: { children: React.ReactNode }) => <div data-testid="rectangle">{children}</div>,
    Polygon: ({ children }: { children: React.ReactNode }) => <div data-testid="polygon">{children}</div>,
    Polyline: ({ children }: { children: React.ReactNode }) => <div data-testid="polyline">{children}</div>,
    useMap: () => mockMap,
}));

import { PortalMap } from '@/components/portal/PortalMap';

const filters: PortalFilters = {
    query: null,
    type: [],
    keywords: [],
    freeKeywords: [],
    thesaurusKeywords: [],
    datacenter: [],
    bounds: null,
    temporal: null,
};

const response = (overrides: Partial<PortalMapResponse> = {}): PortalMapResponse => ({
    schemaVersion: 1,
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
        mapQueryState.result = {
            data: undefined,
            isLoading: false,
            isFetching: false,
            isError: false,
            refetch: vi.fn(),
        };
    });

    it('requests map data only after Leaflet reports a visible viewport', async () => {
        render(<PortalMap filters={filters} />);

        await waitFor(() =>
            expect(usePortalMapDataMock).toHaveBeenCalledWith(
                filters,
                expect.objectContaining({ north: 53, south: 51, east: 14, west: 12, width: 800, height: 600, zoom: 4 }),
                false,
            ),
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

        render(<PortalMap filters={filters} />);

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

        render(<PortalMap filters={filters} />);

        expect(screen.getAllByTestId(testId)[0]).toBeInTheDocument();
        expect(screen.getAllByText('Mapped resource')[0]).toBeInTheDocument();
    });

    it('reports move-end bounds to the spatial filter while always refreshing technical map data', async () => {
        const onViewportChange = vi.fn();
        render(<PortalMap filters={filters} geoFilterEnabled onViewportChange={onViewportChange} />);

        await waitFor(() => expect(mapEvents.has('moveend')).toBe(true));
        act(() => mapEvents.get('moveend')?.());

        expect(onViewportChange).toHaveBeenCalledWith({ north: 53, south: 51, east: 14, west: 12 });
        expect(usePortalMapDataMock).toHaveBeenLastCalledWith(filters, expect.objectContaining({ width: 800, height: 600 }), false);
    });

    it('shows loading, empty, and recoverable error feedback', () => {
        mapQueryState.result.data = response();
        mapQueryState.result.isFetching = true;
        const { rerender } = render(<PortalMap filters={filters} />);
        expect(screen.getAllByRole('status')[0]).toHaveTextContent('Updating map');
        expect(screen.getAllByText(/No geographic data/)[0]).toBeInTheDocument();

        mapQueryState.result.isFetching = false;
        mapQueryState.result.isError = true;
        rerender(<PortalMap filters={filters} />);
        fireEvent.click(screen.getAllByRole('button', { name: /try again/i })[0]);
        expect(mapQueryState.result.refetch).toHaveBeenCalled();
    });

    it('reports the total location count returned with an extent request', () => {
        const onLocationCountChange = vi.fn();
        mapQueryState.result.data = response({ meta: { ...response().meta, totalLocations: 35_638, visibleLocations: 120 } });

        render(<PortalMap filters={filters} onLocationCountChange={onLocationCountChange} />);

        expect(onLocationCountChange).toHaveBeenCalledWith(35_638);
        expect(screen.getAllByText(/35[.,]638 locations/)[0]).toBeInTheDocument();
    });
});
