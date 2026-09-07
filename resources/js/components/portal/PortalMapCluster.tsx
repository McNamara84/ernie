import L from 'leaflet';
import { useEffect } from 'react';
import { useMap } from 'react-leaflet';

import { createCircleMarkerIcon, createIgsnMarkerIcon, createPieChartSvg, getClusterSize, renderPopupHtml } from '@/lib/portal-map-config';
import { rebaseLongitude, unwrapLongitudeBounds } from '@/lib/portal-map-longitude';
import type { PortalMapFeature, PortalMapResourceFeature, PortalResource } from '@/types/portal';

interface ClusterLayerProps {
    features: PortalMapFeature[];
}

function popupResource(feature: PortalMapResourceFeature): PortalResource {
    const type = feature.resource.resourceType;

    return {
        id: feature.resource.id,
        doi: feature.resource.identifier,
        title: feature.resource.title,
        abstract: null,
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
export function ClusterLayer({ features }: ClusterLayerProps) {
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
                    icon: L.divIcon({
                        html: createPieChartSvg(composition.counts, feature.count, size, composition.dimension),
                        className: 'portal-pie-cluster',
                        iconSize: [size, size],
                        iconAnchor: [size / 2, size / 2],
                    }),
                });

                marker.on('click', () => {
                    const displayBounds = unwrapLongitudeBounds(feature.bounds, referenceLongitude);
                    const bounds = L.latLngBounds([feature.bounds.south, displayBounds.west], [feature.bounds.north, displayBounds.east]);

                    if (bounds.isValid() && !bounds.getNorthEast().equals(bounds.getSouthWest())) {
                        map.fitBounds(bounds, { padding: [30, 30], maxZoom: Math.min(18, map.getZoom() + 4) });
                    } else {
                        map.setView([feature.position.lat, displayLongitude], Math.min(18, map.getZoom() + 2), { animate: true });
                    }
                });

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

            marker.bindPopup(renderPopupHtml(popupResource(feature)), { minWidth: 200, maxWidth: 280 });
            layer.addLayer(marker);
        });

        layer.addTo(map);

        return () => {
            map.removeLayer(layer);
        };
    }, [features, map]);

    return null;
}
