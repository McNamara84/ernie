import { act, render, waitFor } from '@tests/vitest/utils/render';
import * as L from 'leaflet';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

let map: L.Map;
let container: HTMLDivElement;

vi.mock('maplibre-gl/dist/maplibre-gl.css', () => ({}));
vi.mock('maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url', () => ({ default: '/maplibre-worker.js' }));
vi.mock('react-leaflet', () => ({ useMap: () => map }));
vi.mock('@americana/diplomat', () => ({ getGlobalStateForLocalization: vi.fn(), localizeStyle: vi.fn() }));

import { PortalBasemap } from '@/components/portal/PortalBasemap';

describe('PortalBasemap with an unavailable WebGL context', () => {
    beforeEach(() => {
        container = document.createElement('div');
        document.body.append(container);
        map = L.map(container).setView([0, 0], 2);
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
    });

    afterEach(() => {
        map.remove();
        container.remove();
        vi.restoreAllMocks();
    });

    it('removes the partially registered layer and permits subsequent map updates and React unmount', async () => {
        const onStatusChange = vi.fn();
        const { unmount } = render(
            <PortalBasemap
                config={{ provider: 'openfreemap', styleUrl: 'https://tiles.openfreemap.org/styles/liberty', language: 'en' }}
                maxZoom={18}
                onStatusChange={onStatusChange}
            />,
        );

        await waitFor(() => expect(onStatusChange).toHaveBeenLastCalledWith('error'));
        expect(console.error).toHaveBeenCalledWith(
            'Portal basemap initialization failed.',
            expect.objectContaining({ message: expect.stringMatching(/WebGL/i) }),
        );
        const remainingLayers: L.Layer[] = [];
        map.eachLayer((layer) => remainingLayers.push(layer));
        expect(remainingLayers).toEqual([]);
        expect(container.querySelector('.leaflet-gl-layer')).toBeNull();
        expect(() => act(() => map.setView([10, 20], 3))).not.toThrow();
        expect(unmount).not.toThrow();
    });
});
