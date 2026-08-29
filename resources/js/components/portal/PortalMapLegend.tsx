import { useMemo } from 'react';

import { getResourceTypeColor, isIgsnType } from '@/lib/portal-map-config';
import type { PortalMapFeature, PortalResource } from '@/types/portal';

interface PortalMapLegendProps {
    resources?: PortalResource[];
    features?: PortalMapFeature[];
}

/**
 * Dynamic map legend showing only the resource types currently visible on the map.
 * Positioned as an overlay in the bottom-right corner of the map container.
 */
export function PortalMapLegend({ resources = [], features = [] }: PortalMapLegendProps) {
    const visibleTypes = useMemo(() => {
        const typeMap = new Map<string, string>();
        for (const r of resources) {
            const slug = r.resourceTypeSlug ?? 'other';
            if (!typeMap.has(slug)) {
                typeMap.set(slug, r.resourceType);
            }
        }

        for (const feature of features) {
            if (feature.kind === 'resource') {
                const type = feature.resource.resourceType;
                const slug = type?.slug ?? 'other';
                typeMap.set(slug, type?.name ?? 'Other');
                continue;
            }

            for (const slug of Object.keys(feature.resourceTypeCounts)) {
                if (!typeMap.has(slug)) {
                    typeMap.set(
                        slug,
                        slug === 'physical-object'
                            ? 'Physical Object'
                            : slug
                                  .split('-')
                                  .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
                                  .join(' '),
                    );
                }
            }
        }
        return [...typeMap.entries()].sort(([a], [b]) => {
            if (a === 'physical-object') return 1;
            if (b === 'physical-object') return -1;
            return a.localeCompare(b);
        });
    }, [features, resources]);

    if (visibleTypes.length === 0) return null;

    return (
        <div
            data-testid="portal-map-legend"
            className="absolute right-4 bottom-4 z-1000 max-h-50 overflow-y-auto rounded-lg border bg-background/90 px-3 py-2 text-xs shadow-md backdrop-blur-sm"
        >
            <div className="mb-1 font-semibold text-muted-foreground">Resource Types</div>
            <div className="space-y-1">
                {visibleTypes.map(([slug, name]) => (
                    <div key={slug} className="flex items-center gap-2" data-testid={`legend-item-${slug}`}>
                        {isIgsnType(slug) ? (
                            <span
                                className="inline-block h-3 w-3 rotate-45 rounded-[1px] border border-white"
                                style={{ backgroundColor: getResourceTypeColor(slug) }}
                            />
                        ) : (
                            <span
                                className="inline-block h-3 w-3 rounded-full border border-white"
                                style={{ backgroundColor: getResourceTypeColor(slug) }}
                            />
                        )}
                        <span>{name}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
