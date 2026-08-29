import '@testing-library/jest-dom/vitest';

import { render } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalMapFeature } from '@/types/portal';

const leafletState = vi.hoisted(() => ({
    markers: [] as Array<{
        position: [number, number];
        options: Record<string, unknown>;
        events: Record<string, () => void>;
        bindPopup: ReturnType<typeof vi.fn>;
    }>,
    layers: [] as unknown[],
}));

const mapMock = vi.hoisted(() => ({
    fitBounds: vi.fn(),
    setView: vi.fn(),
    getZoom: vi.fn(() => 5),
    removeLayer: vi.fn(),
}));

vi.mock('react-leaflet', () => ({ useMap: () => mapMock }));
vi.mock('leaflet', () => ({
    default: {
        divIcon: vi.fn((options) => options),
        layerGroup: vi.fn(() => ({
            addLayer: (layer: unknown) => leafletState.layers.push(layer),
            addTo: vi.fn(),
        })),
        marker: vi.fn((position: [number, number], options: Record<string, unknown>) => {
            const marker = {
                position,
                options,
                events: {} as Record<string, () => void>,
                on(event: string, callback: () => void) {
                    marker.events[event] = callback;
                },
                bindPopup: vi.fn(),
            };
            leafletState.markers.push(marker);
            return marker;
        }),
        latLngBounds: vi.fn(() => ({
            isValid: () => true,
            getNorthEast: () => ({ equals: () => false }),
            getSouthWest: () => ({}),
        })),
    },
}));

import { ClusterLayer } from '@/components/portal/PortalMapCluster';

describe('PortalMapCluster', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        leafletState.markers = [];
        leafletState.layers = [];
    });

    it('renders the server-provided cluster distribution and fits its bounds on click', () => {
        const features: PortalMapFeature[] = [
            {
                kind: 'cluster',
                id: 'z5:1:2',
                position: { lat: 52, lng: 13 },
                bounds: { north: 53, south: 51, east: 14, west: 12 },
                count: 25,
                resourceTypeCounts: { dataset: 20, 'physical-object': 5 },
            },
        ];

        render(<ClusterLayer features={features} />);

        expect(leafletState.markers).toHaveLength(1);
        expect(String((leafletState.markers[0].options.icon as { html: string }).html)).toContain('25');
        leafletState.markers[0].events.click();
        expect(mapMock.fitBounds).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ maxZoom: 9 }));
    });

    it('zooms toward an antimeridian cluster without creating invalid Leaflet bounds', () => {
        const features: PortalMapFeature[] = [
            {
                kind: 'cluster',
                id: 'z5:edge',
                position: { lat: 0, lng: 180 },
                bounds: { north: 5, south: -5, west: 170, east: -170 },
                count: 2,
                resourceTypeCounts: { dataset: 2 },
            },
        ];

        render(<ClusterLayer features={features} />);
        leafletState.markers[0].events.click();

        expect(mapMock.setView).toHaveBeenCalledWith([0, 180], 7, { animate: true });
        expect(mapMock.fitBounds).not.toHaveBeenCalled();
    });

    it('binds a resource popup only for returned point details', () => {
        const features: PortalMapFeature[] = [
            {
                kind: 'resource',
                id: '12',
                position: { lat: 52.5, lng: 13.4 },
                bounds: { north: 52.5, south: 52.5, east: 13.4, west: 13.4 },
                geometry: { type: 'point', latitude: 52.5, longitude: 13.4 },
                resource: {
                    id: 4,
                    identifier: '10.5880/test',
                    title: '<Mapped sample>',
                    creators: [{ name: 'Ada Example' }],
                    resourceType: { slug: 'physical-object', name: 'Physical Object' },
                    landingPageUrl: '/10.5880/test/mapped-sample',
                },
            },
        ];

        render(<ClusterLayer features={features} />);

        expect(leafletState.markers).toHaveLength(1);
        expect(leafletState.markers[0].bindPopup).toHaveBeenCalledWith(expect.stringContaining('&lt;Mapped sample&gt;'), {
            minWidth: 200,
            maxWidth: 280,
        });
    });
});
