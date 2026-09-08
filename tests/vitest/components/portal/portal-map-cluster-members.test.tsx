import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalMapClusterMembersResponse, PortalMapResourceFeature } from '@/types/portal';

const leafletState = vi.hoisted(() => ({
    groups: [] as Array<{
        addLayer: ReturnType<typeof vi.fn>;
        getVisibleParent: ReturnType<typeof vi.fn>;
        spiderfy: ReturnType<typeof vi.fn>;
    }>,
    markers: [] as Array<{
        position: [number, number];
        bindPopup: ReturnType<typeof vi.fn>;
        openPopup: ReturnType<typeof vi.fn>;
    }>,
    visibleParentMode: 'cluster' as 'cluster' | 'none',
}));

const mapMock = vi.hoisted(() => ({
    addLayer: vi.fn(),
    removeLayer: vi.fn(),
    getCenter: vi.fn(() => ({ lat: 0, lng: 180 })),
}));

const renderPopupHtmlMock = vi.hoisted(() => vi.fn(() => '<p>popup</p>'));

vi.mock('leaflet.markercluster/dist/MarkerCluster.css', () => ({}));
vi.mock('leaflet.markercluster/dist/MarkerCluster.Default.css', () => ({}));
vi.mock('leaflet.markercluster', () => ({}));
vi.mock('react-leaflet', () => ({ useMap: () => mapMock }));
vi.mock('@/components/portal/PortalMapCluster', () => ({ portalMapPopupResource: (feature: PortalMapResourceFeature) => feature.resource }));
vi.mock('@/lib/portal-map-config', () => ({
    createCircleMarkerIcon: vi.fn((slug: string | null) => ({ kind: 'doi', slug })),
    createIgsnMarkerIcon: vi.fn((material?: string) => ({ kind: 'igsn', material })),
    renderPopupHtml: renderPopupHtmlMock,
}));
vi.mock('leaflet', () => ({
    default: {
        markerClusterGroup: vi.fn(() => {
            const group = {
                addLayer: vi.fn(),
                getVisibleParent: vi.fn(() => (leafletState.visibleParentMode === 'cluster' ? group : null)),
                spiderfy: vi.fn(),
            };
            leafletState.groups.push(group);

            return group;
        }),
        marker: vi.fn((position: [number, number]) => {
            const marker = { position, bindPopup: vi.fn(), openPopup: vi.fn() };
            leafletState.markers.push(marker);

            return marker;
        }),
    },
}));

import { ClusterMembersLayer, ClusterMembersPanel } from '@/components/portal/PortalMapClusterMembers';

function member(id: number, overrides: Partial<PortalMapResourceFeature> = {}): PortalMapResourceFeature {
    return {
        kind: 'resource',
        id: String(id),
        position: { lat: 52.5, lng: -179 },
        bounds: { north: 52.5, south: 52.5, east: -179, west: -179 },
        geometry: { type: 'point', latitude: 52.5, longitude: -179 },
        resource: {
            id,
            identifier: `10.5880/${id}`,
            title: `Resource ${id}`,
            creators: [],
            resourceType: { slug: 'dataset', name: 'Dataset' },
            landingPageUrl: `/resource/${id}`,
        },
        ...overrides,
    };
}

function result(overrides: Partial<PortalMapClusterMembersResponse> = {}): PortalMapClusterMembersResponse {
    return {
        schemaVersion: 1,
        clusterId: 'z18:140812:37114',
        total: 2,
        members: [member(1), member(2)],
        pagination: { currentPage: 1, lastPage: 2, perPage: 50 },
        ...overrides,
    };
}

describe('ClusterMembersLayer', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        leafletState.groups = [];
        leafletState.markers = [];
        leafletState.visibleParentMode = 'cluster';
    });

    afterEach(() => vi.useRealTimers());

    it('spiderfies a bounded terminal cluster and removes it during cleanup', () => {
        const members = [member(1), member(2)];
        const { unmount } = render(<ClusterMembersLayer members={members} total={2} />);

        expect(leafletState.groups).toHaveLength(1);
        expect(leafletState.markers.map((marker) => marker.position)).toEqual([
            [52.5, 181],
            [52.5, 181],
        ]);
        expect(leafletState.groups[0].addLayer).toHaveBeenCalledTimes(2);
        expect(mapMock.addLayer).toHaveBeenCalledWith(leafletState.groups[0]);
        expect(leafletState.markers[0].bindPopup).toHaveBeenCalledWith('<p>popup</p>', { minWidth: 200, maxWidth: 280 });

        vi.runOnlyPendingTimers();
        expect(leafletState.groups[0].spiderfy).toHaveBeenCalledOnce();

        unmount();
        expect(mapMock.removeLayer).toHaveBeenCalledWith(leafletState.groups[0]);
    });

    it('opens a single marker directly and does not render incomplete or oversized member sets', () => {
        leafletState.visibleParentMode = 'none';
        const { rerender } = render(<ClusterMembersLayer members={[member(1)]} total={1} />);
        vi.runOnlyPendingTimers();
        expect(leafletState.markers[0].openPopup).toHaveBeenCalledOnce();

        mapMock.addLayer.mockClear();
        rerender(<ClusterMembersLayer members={Array.from({ length: 20 }, (_, index) => member(index + 1))} total={39} />);
        expect(mapMock.addLayer).not.toHaveBeenCalled();

        rerender(<ClusterMembersLayer members={Array.from({ length: 50 }, (_, index) => member(index + 1))} total={51} />);
        expect(mapMock.addLayer).not.toHaveBeenCalled();
    });
});

describe('ClusterMembersPanel', () => {
    it('renders member details, explains oversized clusters, and pages in both directions', () => {
        const onPageChange = vi.fn();
        const onClose = vi.fn();
        const panelResult = result({
            total: 75,
            pagination: { currentPage: 2, lastPage: 3, perPage: 50 },
            members: [
                member(3, {
                    resource: {
                        ...member(3).resource,
                        title: 'Porewater sample',
                        resourceType: { slug: 'physical-object', name: 'Physical Object' },
                        presentation: { dimension: 'material', key: 'liquid', label: 'Liquid', status: 'value' },
                    },
                }),
            ],
        });

        render(<ClusterMembersPanel result={panelResult} isLoading={false} isError={false} onClose={onClose} onPageChange={onPageChange} />);

        const panel = screen.getByRole('region', { name: 'Records at this map location' });
        expect(panel).toHaveFocus();
        expect(panel).toHaveTextContent('Records at this location (75)');
        expect(screen.getByText('Porewater sample')).toBeInTheDocument();
        expect(screen.getByText('Material: Liquid')).toBeInTheDocument();
        expect(screen.getByText(/too large to spread/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /view details/i })).toHaveAttribute('href', '/resource/3');

        fireEvent.click(screen.getByRole('button', { name: /previous/i }));
        fireEvent.click(screen.getByRole('button', { name: /next/i }));
        fireEvent.click(screen.getByRole('button', { name: /close cluster results/i }));
        expect(onPageChange.mock.calls).toEqual([[1], [3]]);
        expect(onClose).toHaveBeenCalledOnce();

        fireEvent.keyDown(panel, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledTimes(2);
    });

    it('renders loading, error, and empty states', () => {
        const props = { onClose: vi.fn(), onPageChange: vi.fn() };
        const { rerender } = render(<ClusterMembersPanel result={undefined} isLoading isError={false} {...props} />);
        expect(screen.getByText('Loading records...')).toBeInTheDocument();

        rerender(<ClusterMembersPanel result={undefined} isLoading={false} isError {...props} />);
        expect(screen.getByText(/could not be loaded/)).toBeInTheDocument();

        rerender(<ClusterMembersPanel result={result({ total: 0, members: [] })} isLoading={false} isError={false} {...props} />);
        expect(screen.getByText('No records remain in this cluster.')).toBeInTheDocument();
    });
});
