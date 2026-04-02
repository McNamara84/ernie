import 'leaflet.markercluster';

import L from 'leaflet';
import { useEffect, useRef } from 'react';
import { useMap } from 'react-leaflet';

import {
    createCircleMarkerIcon,
    createIgsnMarkerIcon,
    createPieChartSvg,
    getClusterSize,
    getResourceTypeColor,
    renderPopupHtml,
} from '@/lib/portal-map-config';
import type { PortalResource } from '@/types/portal';

/**
 * Custom iconCreateFunction for MarkerClusterGroup.
 * Renders an SVG pie chart showing the proportion of each resource type within the cluster.
 */
function createClusterIcon(cluster: L.MarkerCluster): L.DivIcon {
    const markers = cluster.getAllChildMarkers();
    const typeCounts: Record<string, number> = {};

    markers.forEach((marker) => {
        const slug = (marker.options as L.MarkerOptions).resourceTypeSlug ?? 'other';
        typeCounts[slug] = (typeCounts[slug] ?? 0) + 1;
    });

    const total = markers.length;
    const size = getClusterSize(total);
    const svg = createPieChartSvg(typeCounts, total, size);

    return L.divIcon({
        html: svg,
        className: 'portal-pie-cluster',
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
    });
}

interface ClusterLayerProps {
    resources: PortalResource[];
}

/**
 * Imperative Leaflet MarkerClusterGroup layer rendered via useMap().
 * Handles all point markers — non-point geo shapes (box, polygon, line)
 * are rendered separately as React-Leaflet declarative components.
 */
export function ClusterLayer({ resources }: ClusterLayerProps) {
    const map = useMap();
    const clusterGroupRef = useRef<L.MarkerClusterGroup | null>(null);

    useEffect(() => {
        if (clusterGroupRef.current) {
            map.removeLayer(clusterGroupRef.current);
        }

        const clusterGroup = L.markerClusterGroup({
            maxClusterRadius: 60,
            disableClusteringAtZoom: 18,
            spiderfyOnMaxZoom: true,
            showCoverageOnHover: false,
            iconCreateFunction: createClusterIcon,
        });

        resources.forEach((resource) => {
            resource.geoLocations.forEach((geo) => {
                if (geo.type !== 'point' || !geo.point) return;

                const latlng = L.latLng(geo.point.lat, geo.point.lng);
                const icon = resource.isIgsn
                    ? createIgsnMarkerIcon()
                    : createCircleMarkerIcon(resource.resourceTypeSlug);

                const marker = L.marker(latlng, {
                    icon,
                    resourceTypeSlug: resource.resourceTypeSlug ?? 'other',
                } as L.MarkerOptions);

                const popupHtml = renderPopupHtml(resource);
                marker.bindPopup(popupHtml, { minWidth: 200, maxWidth: 280 });

                clusterGroup.addLayer(marker);
            });
        });

        map.addLayer(clusterGroup);
        clusterGroupRef.current = clusterGroup;

        return () => {
            map.removeLayer(clusterGroup);
        };
    }, [map, resources]);

    return null;
}
