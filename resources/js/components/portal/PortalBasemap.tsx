import 'maplibre-gl/dist/maplibre-gl.css';

import type * as L from 'leaflet';
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import { useEffect } from 'react';
import { useMap } from 'react-leaflet';

import type { PortalBasemapConfig } from '@/types/portal';

export type PortalBasemapStatus = 'loading' | 'ready' | 'error';

export const OPENFREEMAP_ATTRIBUTION =
    '<a href="https://openfreemap.org/" target="_blank" rel="noopener noreferrer">OpenFreeMap</a> ' +
    '<a href="https://www.openmaptiles.org/" target="_blank" rel="noopener noreferrer">&copy; OpenMapTiles</a> ' +
    'Data from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>';

interface PortalBasemapProps {
    config: PortalBasemapConfig;
    maxZoom: number;
    onStatusChange: (status: PortalBasemapStatus) => void;
}

export function PortalBasemap({ config, maxZoom, onStatusChange }: PortalBasemapProps) {
    const map = useMap();
    const styleUrl = config.styleUrl.trim();
    const hasConfiguration = styleUrl !== '';
    const language = config.language;

    useEffect(() => {
        let disposed = false;
        let layer: L.MaplibreGL | null = null;
        let localized = false;
        let localizationAttempted = false;
        let loadFailed = false;
        let recoveryStarted = false;

        onStatusChange('loading');
        map.attributionControl?.addAttribution(OPENFREEMAP_ATTRIBUTION);

        if (!hasConfiguration) {
            onStatusChange('error');
            return () => map.attributionControl?.removeAttribution(OPENFREEMAP_ATTRIBUTION);
        }

        const initialize = async () => {
            try {
                const [{ maplibreGL }, { getGlobalStateForLocalization, localizeStyle }, { setWorkerUrl }] = await Promise.all([
                    import('@maplibre/maplibre-gl-leaflet'),
                    import('@americana/diplomat'),
                    import('maplibre-gl'),
                ]);

                if (disposed) return;

                setWorkerUrl(maplibreWorkerUrl);
                layer = maplibreGL({
                    style: styleUrl,
                    interactive: false,
                    maxZoom,
                    attributionControl: false,
                });
                layer.addTo(map);
                const maplibreMap = layer.getMaplibreMap();

                const reportLoadError = (error: unknown) => {
                    if (disposed) return;

                    loadFailed = true;
                    recoveryStarted = false;
                    console.error('Portal basemap loading failed.', error);
                    onStatusChange('error');
                };

                const handleStyleData = () => {
                    if (disposed || localizationAttempted) return;

                    localizationAttempted = true;

                    try {
                        const localizationState = getGlobalStateForLocalization([language]);
                        for (const [key, value] of Object.entries(localizationState)) {
                            maplibreMap.setGlobalStateProperty(key, value);
                        }
                        localizeStyle(maplibreMap, [language], { glossLocalNames: false });
                        localized = true;
                    } catch (error) {
                        reportLoadError(error);
                    }
                };
                const handleDataLoading = () => {
                    if (!disposed && loadFailed) recoveryStarted = true;
                };
                const handleIdle = () => {
                    // MapLibre also becomes idle when requests finish in an errored state.
                    // Require a new loading cycle before treating a post-load error as recovered.
                    if (disposed || !localized || (loadFailed && !recoveryStarted)) return;

                    loadFailed = false;
                    recoveryStarted = false;
                    onStatusChange('ready');
                };
                const handleError = (event: { error: unknown }) => reportLoadError(event.error);

                maplibreMap.on('styledata', handleStyleData);
                maplibreMap.on('dataloading', handleDataLoading);
                maplibreMap.on('idle', handleIdle);
                maplibreMap.on('error', handleError);
            } catch (error) {
                if (!disposed) {
                    console.error('Portal basemap initialization failed.', error);
                    onStatusChange('error');
                }
            }
        };

        void initialize();

        return () => {
            disposed = true;
            layer?.remove();
            map.attributionControl?.removeAttribution(OPENFREEMAP_ATTRIBUTION);
        };
    }, [hasConfiguration, language, map, maxZoom, onStatusChange, styleUrl]);

    return null;
}
