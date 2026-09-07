import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import 'leaflet.markercluster';

import L from 'leaflet';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { useMap } from 'react-leaflet';

import { Button } from '@/components/ui/button';
import { createCircleMarkerIcon, createIgsnMarkerIcon, renderPopupHtml } from '@/lib/portal-map-config';
import { rebaseLongitude } from '@/lib/portal-map-longitude';
import type { PortalMapClusterMembersResponse, PortalMapResourceFeature } from '@/types/portal';

import { portalMapPopupResource } from './PortalMapCluster';

export const PORTAL_MAP_SPIDERFY_LIMIT = 50;

export function ClusterMembersLayer({ members, total }: { members: PortalMapResourceFeature[]; total: number }) {
    const map = useMap();

    useEffect(() => {
        if (members.length === 0 || total > PORTAL_MAP_SPIDERFY_LIMIT) return;

        const group = L.markerClusterGroup({
            maxClusterRadius: 40,
            showCoverageOnHover: false,
            spiderfyOnMaxZoom: true,
            zoomToBoundsOnClick: false,
        });
        const referenceLongitude = map.getCenter().lng;
        const markers = members.map((feature) => {
            const typeSlug = feature.resource.resourceType?.slug ?? null;
            const presentation = feature.resource.presentation;
            const longitude = rebaseLongitude(feature.position.lng, referenceLongitude);
            const marker = L.marker([feature.position.lat, longitude], {
                icon:
                    typeSlug === 'physical-object'
                        ? createIgsnMarkerIcon(presentation?.dimension === 'material' ? presentation.key : undefined)
                        : createCircleMarkerIcon(typeSlug),
            });

            marker.bindPopup(renderPopupHtml(portalMapPopupResource(feature)), { minWidth: 200, maxWidth: 280 });
            group.addLayer(marker);

            return marker;
        });

        map.addLayer(group);
        const openTimer = window.setTimeout(() => {
            const visibleParent = group.getVisibleParent(markers[0]) as (L.Marker & { spiderfy?: () => void }) | null;
            if (visibleParent?.spiderfy) {
                visibleParent.spiderfy();
            } else if (markers.length === 1) {
                markers[0].openPopup();
            }
        }, 0);

        return () => {
            window.clearTimeout(openTimer);
            map.removeLayer(group);
        };
    }, [map, members, total]);

    return null;
}

interface ClusterMembersPanelProps {
    result: PortalMapClusterMembersResponse | undefined;
    isLoading: boolean;
    isError: boolean;
    onClose: () => void;
    onPageChange: (page: number) => void;
}

export function ClusterMembersPanel({ result, isLoading, isError, onClose, onPageChange }: ClusterMembersPanelProps) {
    const pagination = result?.pagination;
    const panelRef = useRef<HTMLElement>(null);

    useEffect(() => panelRef.current?.focus(), []);

    return (
        <section
            ref={panelRef}
            tabIndex={-1}
            aria-label="Records at this map location"
            aria-live="polite"
            onKeyDown={(event) => {
                if (event.key === 'Escape') onClose();
            }}
            className="absolute right-3 bottom-3 z-1000 flex max-h-[min(28rem,70%)] w-[min(24rem,calc(100%-1.5rem))] flex-col overflow-hidden rounded-md border bg-background/95 shadow-lg backdrop-blur"
            data-testid="portal-map-cluster-members"
        >
            <div className="flex items-center justify-between gap-3 border-b px-3 py-2">
                <h3 className="text-sm font-semibold">Records at this location{result ? ` (${result.total})` : ''}</h3>
                <Button variant="ghost" size="icon-xs" aria-label="Close cluster results" onClick={onClose}>
                    <X className="h-4 w-4" />
                </Button>
            </div>

            <div className="min-h-16 overflow-y-auto p-3">
                {isLoading && <p className="text-sm text-muted-foreground">Loading records...</p>}
                {isError && <p className="text-sm text-destructive">The records in this cluster could not be loaded.</p>}
                {!isLoading && !isError && result?.members.length === 0 && (
                    <p className="text-sm text-muted-foreground">No records remain in this cluster.</p>
                )}
                {!isLoading && !isError && result && result.total > PORTAL_MAP_SPIDERFY_LIMIT && (
                    <p className="mb-3 text-xs text-muted-foreground">
                        This cluster is too large to spread on the map. Refine the filters or use the list.
                    </p>
                )}

                {result && (
                    <ul className="space-y-2">
                        {result.members.map((feature) => {
                            const presentation = feature.resource.presentation;
                            const category =
                                presentation?.dimension === 'material'
                                    ? `Material: ${presentation.label}`
                                    : (feature.resource.resourceType?.name ?? 'Other');

                            return (
                                <li key={feature.id} className="rounded border p-2 text-sm">
                                    <p className="line-clamp-2 font-medium">{feature.resource.title}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">{category}</p>
                                    {feature.resource.landingPageUrl && (
                                        <a
                                            href={feature.resource.landingPageUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mt-1 inline-block text-xs font-medium text-primary hover:underline"
                                        >
                                            View details →
                                        </a>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {pagination && pagination.lastPage > 1 && (
                <div className="flex items-center justify-between border-t px-3 py-2 text-xs">
                    <Button
                        variant="outline"
                        size="xs"
                        disabled={pagination.currentPage <= 1}
                        onClick={() => onPageChange(pagination.currentPage - 1)}
                    >
                        <ChevronLeft className="h-3.5 w-3.5" /> Previous
                    </Button>
                    <span>
                        Page {pagination.currentPage} of {pagination.lastPage}
                    </span>
                    <Button
                        variant="outline"
                        size="xs"
                        disabled={pagination.currentPage >= pagination.lastPage}
                        onClick={() => onPageChange(pagination.currentPage + 1)}
                    >
                        Next <ChevronRight className="h-3.5 w-3.5" />
                    </Button>
                </div>
            )}
        </section>
    );
}
