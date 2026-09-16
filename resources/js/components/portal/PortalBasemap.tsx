import 'maplibre-gl/dist/maplibre-gl.css';

import type * as L from 'leaflet';
import { useEffect } from 'react';
import { useMap } from 'react-leaflet';

import type { PortalMapTilerBasemapConfig } from '@/types/portal';

export type PortalBasemapStatus = 'loading' | 'ready' | 'error';

export const MAPTILER_ATTRIBUTION =
    '<a href="https://www.maptiler.com/copyright/" target="_blank">&copy; MapTiler</a> ' +
    '<a href="https://www.openstreetmap.org/copyright" target="_blank">&copy; OpenStreetMap contributors</a>';

interface PortalBasemapProps {
    config: PortalMapTilerBasemapConfig;
    maxZoom: number;
    onStatusChange: (status: PortalBasemapStatus) => void;
}

export function buildMaptilerStyleUrl(config: PortalMapTilerBasemapConfig): string {
    const style = encodeURIComponent(config.style.trim());
    const apiKey = encodeURIComponent(config.apiKey.trim());

    return `https://api.maptiler.com/maps/${style}/style.json?key=${apiKey}`;
}

export function PortalBasemap({ config, maxZoom, onStatusChange }: PortalBasemapProps) {
    const map = useMap();
    const hasConfiguration = config.apiKey.trim() !== '' && config.style.trim() !== '';
    const language = config.language;
    const styleUrl = buildMaptilerStyleUrl(config);

    useEffect(() => {
        let disposed = false;
        let layer: L.MaplibreGL | null = null;
        let localized = false;

        onStatusChange('loading');
        map.attributionControl?.addAttribution(MAPTILER_ATTRIBUTION);

        if (!hasConfiguration) {
            onStatusChange('error');
            return () => map.attributionControl?.removeAttribution(MAPTILER_ATTRIBUTION);
        }

        const initialize = async () => {
            try {
                const [{ maplibreGL }, { localizeStyle }] = await Promise.all([
                    import('@maplibre/maplibre-gl-leaflet'),
                    import('@americana/diplomat'),
                ]);

                if (disposed) return;

                layer = maplibreGL({
                    style: styleUrl,
                    interactive: false,
                    maxZoom,
                    attributionControl: false,
                });
                layer.addTo(map);
                const maplibreMap = layer.getMaplibreMap();

                maplibreMap.once('styledata', () => {
                    if (disposed || localized) return;

                    try {
                        localized = true;
                        localizeStyle(maplibreMap, [language], { glossLocalNames: false });
                        onStatusChange('ready');
                    } catch {
                        onStatusChange('error');
                    }
                });
                maplibreMap.on('error', () => {
                    if (!disposed && !localized) onStatusChange('error');
                });
            } catch {
                if (!disposed) onStatusChange('error');
            }
        };

        void initialize();

        return () => {
            disposed = true;
            layer?.remove();
            map.attributionControl?.removeAttribution(MAPTILER_ATTRIBUTION);
        };
    }, [hasConfiguration, language, map, maxZoom, onStatusChange, styleUrl]);

    return null;
}
