import L from 'leaflet';
import { useEffect } from 'react';
import { useMap } from 'react-leaflet';

import { createCircleMarkerIcon, createIgsnMarkerIcon, createPieChartSvg, getClusterSize, renderPopupHtml } from '@/lib/portal-map-config';
import { rebaseLongitude, unwrapLongitudeBounds } from '@/lib/portal-map-longitude';
import type { PortalMapFeature, PortalMapResourceFeature, PortalResource } from '@/types/portal';

interface ClusterLayerProps {
    features: PortalMapFeature[];
    maxZoom: number;
    interactive?: boolean;
    onExpandCluster?: (feature: Extract<PortalMapFeature, { kind: 'cluster' }>) => void;
}

export function navigateIntoCluster(map: L.Map, feature: Extract<PortalMapFeature, { kind: 'cluster' }>, maxZoom: number): boolean {
    const currentZoom = map.getZoom();
    if (currentZoom >= maxZoom) return false;

    const referenceLongitude = map.getCenter().lng;
    const navigationBounds = feature.navigationBounds ?? feature.bounds;
    const displayBounds = unwrapLongitudeBounds(navigationBounds, referenceLongitude);
    const bounds = L.latLngBounds([navigationBounds.south, displayBounds.west], [navigationBounds.north, displayBounds.east]);
    const isPoint = bounds.isValid() && bounds.getNorthEast().equals(bounds.getSouthWest());
    const fittedZoom = bounds.isValid() && !isPoint ? map.getBoundsZoom(bounds, false, L.point(30, 30)) : currentZoom + 2;
    const minimumZoom = Math.min(maxZoom, currentZoom + 1);
    const maximumZoom = Math.min(maxZoom, currentZoom + 4);
    const finiteFittedZoom = Number.isFinite(fittedZoom) ? fittedZoom : maximumZoom;
    const targetZoom = Math.max(minimumZoom, Math.min(maximumZoom, finiteFittedZoom));
    const displayLongitude = rebaseLongitude(feature.position.lng, referenceLongitude);

    map.setView([feature.position.lat, displayLongitude], targetZoom, { animate: true });

    return true;
}

export function portalMapPopupResource(feature: PortalMapResourceFeature): PortalResource {
    const type = feature.resource.resourceType;

    return {
        id: feature.resource.id,
        doi: feature.resource.identifier,
        title: feature.resource.title,
        creators: feature.resource.creators,
        year: null,
        resourceType: type?.name ?? 'Other',
        resourceTypeSlug: type?.slug ?? null,
        isIgsn: type?.slug === 'physical-object',
        presentation: feature.resource.presentation,
        igsn: feature.resource.igsn,
        geoLocations: [],
        landingPageUrl: feature.resource.landingPageUrl,
    };
}

/** Render the bounded server clusters and individual point markers. */
export function ClusterLayer({ features, maxZoom, interactive = true, onExpandCluster }: ClusterLayerProps) {
    const map = useMap();

    useEffect(() => {
        const layer = L.layerGroup();
        const referenceLongitude = map.getCenter().lng;

        features.forEach((feature) => {
            if (feature.kind === 'cluster') {
                const size = getClusterSize(feature.count);
                const composition = feature.composition ?? {
                    dimension: 'resource-type' as const,
                    counts: feature.resourceTypeCounts,
                };
                const displayLongitude = rebaseLongitude(feature.position.lng, referenceLongitude);
                const marker = L.marker([feature.position.lat, displayLongitude], {
                    interactive,
                    icon: L.divIcon({
                        html: createPieChartSvg(composition.counts, feature.count, size, composition.dimension),
                        className: 'portal-pie-cluster',
                        iconSize: [size, size],
                        iconAnchor: [size / 2, size / 2],
                    }),
                });

                if (interactive) {
                    marker.on('click', () => {
                        if (!navigateIntoCluster(map, feature, maxZoom)) onExpandCluster?.(feature);
                    });
                }

                layer.addLayer(marker);
                return;
            }

            if (feature.geometry.type !== 'point') return;

            const typeSlug = feature.resource.resourceType?.slug ?? null;
            const presentation = feature.resource.presentation;
            const displayLongitude = rebaseLongitude(feature.geometry.longitude, referenceLongitude);
            const marker = L.marker([feature.geometry.latitude, displayLongitude], {
                icon:
                    typeSlug === 'physical-object'
                        ? createIgsnMarkerIcon(presentation?.dimension === 'material' ? presentation.key : undefined)
                        : createCircleMarkerIcon(typeSlug),
            });

            marker.bindPopup(renderPopupHtml(portalMapPopupResource(feature)), { minWidth: 200, maxWidth: 280 });
            layer.addLayer(marker);
        });

        layer.addTo(map);

        return () => {
            map.removeLayer(layer);
        };
    }, [features, interactive, map, maxZoom, onExpandCluster]);

    return null;
}
