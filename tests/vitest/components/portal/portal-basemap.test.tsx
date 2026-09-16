import '@testing-library/jest-dom/vitest';

import { act, render, waitFor } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalMapTilerBasemapConfig } from '@/types/portal';

const maplibreEvents = vi.hoisted(() => new Map<string, (...args: unknown[]) => void>());
const maplibreMapMock = vi.hoisted(() => ({
    on: vi.fn((event: string, callback: (...args: unknown[]) => void) => maplibreEvents.set(event, callback)),
}));
const layerMock = vi.hoisted(() => ({
    addTo: vi.fn(),
    getMaplibreMap: vi.fn(() => maplibreMapMock),
    remove: vi.fn(),
}));
const maplibreGLMock = vi.hoisted(() => vi.fn(() => layerMock));
const setWorkerUrlMock = vi.hoisted(() => vi.fn());
const localizeStyleMock = vi.hoisted(() => vi.fn());
const attributionControlMock = vi.hoisted(() => ({
    addAttribution: vi.fn(),
    removeAttribution: vi.fn(),
}));
const leafletMapMock = vi.hoisted(() => ({ attributionControl: attributionControlMock }));

vi.mock('maplibre-gl/dist/maplibre-gl.css', () => ({}));
vi.mock('maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url', () => ({ default: '/build/assets/maplibre-gl-worker-test.js' }));
vi.mock('maplibre-gl', () => ({ setWorkerUrl: setWorkerUrlMock }));
vi.mock('react-leaflet', () => ({ useMap: () => leafletMapMock }));
vi.mock('@maplibre/maplibre-gl-leaflet', () => ({ maplibreGL: maplibreGLMock }));
vi.mock('@americana/diplomat', () => ({ localizeStyle: localizeStyleMock }));

import { buildMaptilerStyleUrl, MAPTILER_ATTRIBUTION, PortalBasemap } from '@/components/portal/PortalBasemap';

const config: PortalMapTilerBasemapConfig = {
    provider: 'maptiler',
    style: 'streets-v4',
    language: 'en',
    apiKey: 'test key/+',
};

describe('PortalBasemap', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        maplibreEvents.clear();
        maplibreGLMock.mockImplementation(() => layerMock);
        localizeStyleMock.mockImplementation(() => undefined);
    });

    it('builds an encoded MapTiler Cloud style URL', () => {
        expect(buildMaptilerStyleUrl({ ...config, style: ' custom/style ' })).toBe(
            'https://api.maptiler.com/maps/custom%2Fstyle/style.json?key=test%20key%2F%2B',
        );
    });

    it('protects attribution links that open in a new tab', () => {
        const container = document.createElement('div');
        container.innerHTML = MAPTILER_ATTRIBUTION;

        const links = Array.from(container.querySelectorAll('a'));
        expect(links).toHaveLength(2);
        expect(links.every((link) => link.target === '_blank' && link.rel === 'noopener noreferrer')).toBe(true);
    });

    it('configures the emitted worker before adding a MapLibre layer and localizes all supported labels to English', async () => {
        const onStatusChange = vi.fn();
        const { unmount } = render(<PortalBasemap config={config} maxZoom={9} onStatusChange={onStatusChange} />);

        await waitFor(() => expect(maplibreGLMock).toHaveBeenCalledOnce());
        expect(setWorkerUrlMock).toHaveBeenCalledWith('/build/assets/maplibre-gl-worker-test.js');
        expect(setWorkerUrlMock.mock.invocationCallOrder[0]).toBeLessThan(maplibreGLMock.mock.invocationCallOrder[0]);
        expect(maplibreGLMock).toHaveBeenCalledWith({
            style: 'https://api.maptiler.com/maps/streets-v4/style.json?key=test%20key%2F%2B',
            interactive: false,
            maxZoom: 9,
            attributionControl: false,
        });
        expect(layerMock.addTo).toHaveBeenCalledWith(leafletMapMock);
        expect(attributionControlMock.addAttribution).toHaveBeenCalledWith(MAPTILER_ATTRIBUTION);
        expect(onStatusChange).toHaveBeenCalledWith('loading');

        act(() => maplibreEvents.get('styledata')?.());

        expect(localizeStyleMock).toHaveBeenCalledWith(maplibreMapMock, ['en'], { glossLocalNames: false });
        expect(onStatusChange).toHaveBeenLastCalledWith('loading');

        act(() => maplibreEvents.get('idle')?.());
        expect(onStatusChange).toHaveBeenLastCalledWith('ready');

        act(() => maplibreEvents.get('styledata')?.());
        expect(localizeStyleMock).toHaveBeenCalledOnce();
        expect(onStatusChange).toHaveBeenLastCalledWith('ready');

        unmount();
        expect(layerMock.remove).toHaveBeenCalledOnce();
        expect(attributionControlMock.removeAttribution).toHaveBeenCalledWith(MAPTILER_ATTRIBUTION);
    });

    it('keeps observing resource errors and becomes ready only after a successful recovery cycle', async () => {
        const onStatusChange = vi.fn();
        render(<PortalBasemap config={config} maxZoom={18} onStatusChange={onStatusChange} />);
        await waitFor(() => expect(maplibreEvents.has('idle')).toBe(true));

        act(() => {
            maplibreEvents.get('styledata')?.();
            maplibreEvents.get('idle')?.();
        });
        expect(onStatusChange).toHaveBeenLastCalledWith('ready');

        act(() => maplibreEvents.get('error')?.({ error: new Error('Tile request failed') }));
        expect(onStatusChange).toHaveBeenLastCalledWith('error');

        act(() => maplibreEvents.get('idle')?.());
        expect(onStatusChange).toHaveBeenLastCalledWith('error');

        act(() => {
            maplibreEvents.get('dataloading')?.();
            maplibreEvents.get('error')?.({ error: new Error('Tile retry failed') });
            maplibreEvents.get('idle')?.();
        });
        expect(onStatusChange).toHaveBeenLastCalledWith('error');

        act(() => {
            maplibreEvents.get('dataloading')?.();
            maplibreEvents.get('idle')?.();
        });
        expect(onStatusChange).toHaveBeenLastCalledWith('ready');
    });

    it.each([
        ['API key', { apiKey: ' ' }],
        ['style', { style: ' ' }],
    ])('reports a missing %s without initializing MapLibre', (_field, override) => {
        const onStatusChange = vi.fn();
        const { unmount } = render(<PortalBasemap config={{ ...config, ...override }} maxZoom={18} onStatusChange={onStatusChange} />);

        expect(onStatusChange.mock.calls.map(([status]) => status)).toEqual(['loading', 'error']);
        expect(maplibreGLMock).not.toHaveBeenCalled();
        expect(attributionControlMock.addAttribution).toHaveBeenCalledWith(MAPTILER_ATTRIBUTION);

        unmount();
        expect(attributionControlMock.removeAttribution).toHaveBeenCalledWith(MAPTILER_ATTRIBUTION);
    });

    it('reports initialization and pre-load style errors', async () => {
        const initializationStatus = vi.fn();
        maplibreGLMock.mockImplementationOnce(() => {
            throw new Error('WebGL unavailable');
        });

        const firstRender = render(<PortalBasemap config={config} maxZoom={18} onStatusChange={initializationStatus} />);
        await waitFor(() => expect(initializationStatus).toHaveBeenLastCalledWith('error'));
        firstRender.unmount();

        vi.clearAllMocks();
        maplibreEvents.clear();
        maplibreGLMock.mockImplementation(() => layerMock);
        const styleStatus = vi.fn();
        render(<PortalBasemap config={config} maxZoom={18} onStatusChange={styleStatus} />);
        await waitFor(() => expect(maplibreEvents.has('error')).toBe(true));

        act(() => maplibreEvents.get('error')?.({ error: new Error('Style request failed') }));
        expect(styleStatus).toHaveBeenLastCalledWith('error');
    });

    it('reports localization errors and never adds a layer after unmount', async () => {
        const localizationStatus = vi.fn();
        localizeStyleMock.mockImplementationOnce(() => {
            throw new Error('Unsupported style');
        });
        const firstRender = render(<PortalBasemap config={config} maxZoom={18} onStatusChange={localizationStatus} />);
        await waitFor(() => expect(maplibreEvents.has('styledata')).toBe(true));

        act(() => maplibreEvents.get('styledata')?.());
        expect(localizationStatus).toHaveBeenLastCalledWith('error');
        firstRender.unmount();

        vi.clearAllMocks();
        maplibreEvents.clear();
        const secondRender = render(<PortalBasemap config={config} maxZoom={18} onStatusChange={vi.fn()} />);
        secondRender.unmount();

        await act(async () => Promise.resolve());
        expect(maplibreGLMock).not.toHaveBeenCalled();
    });
});
