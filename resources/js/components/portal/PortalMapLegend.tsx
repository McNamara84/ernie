import { useMemo } from 'react';

import { compareMapCategoryKeys, getMapCategoryColor, getMapCategoryLabel, getMaterialCategoryStyle, isIgsnType } from '@/lib/portal-map-config';
import type { PortalMapFeature, PortalMapVisualizationDimension, PortalResource } from '@/types/portal';

interface PortalMapLegendProps {
    resources?: PortalResource[];
    features?: PortalMapFeature[];
}

interface LegendEntry {
    key: string;
    label: string;
    count: number;
}

function fallbackDimension(resources: PortalResource[], features: PortalMapFeature[]): PortalMapVisualizationDimension {
    for (const feature of features) {
        if (feature.kind === 'cluster' && feature.composition) return feature.composition.dimension;
        if (feature.kind === 'resource' && feature.resource.presentation) return feature.resource.presentation.dimension;
    }

    for (const resource of resources) {
        if (resource.presentation) return resource.presentation.dimension;
    }

    return 'resource-type';
}

/** Dynamic legend for the category dimension represented by the current map payload. */
export function PortalMapLegend({ resources = [], features = [] }: PortalMapLegendProps) {
    const { dimension, entries } = useMemo(() => {
        const resolvedDimension = fallbackDimension(resources, features);
        const categories = new Map<string, LegendEntry>();

        const add = (key: string, count: number, label?: string) => {
            const current = categories.get(key);
            categories.set(key, {
                key,
                label: current?.label ?? getMapCategoryLabel(resolvedDimension, key, label),
                count: (current?.count ?? 0) + count,
            });
        };

        for (const resource of resources) {
            const presentation = resource.presentation;
            if (presentation?.dimension === resolvedDimension) {
                add(presentation.key, 1, presentation.label);
            } else {
                add(resource.resourceTypeSlug ?? 'other', 1, resource.resourceType);
            }
        }

        for (const feature of features) {
            if (feature.kind === 'cluster') {
                const composition = feature.composition?.dimension === resolvedDimension ? feature.composition.counts : feature.resourceTypeCounts;
                for (const [key, count] of Object.entries(composition)) add(key, count);
                continue;
            }

            const presentation = feature.resource.presentation;
            if (presentation?.dimension === resolvedDimension) {
                add(presentation.key, 1, presentation.label);
            } else {
                add(feature.resource.resourceType?.slug ?? 'other', 1, feature.resource.resourceType?.name ?? 'Other');
            }
        }

        return {
            dimension: resolvedDimension,
            entries: [...categories.values()].sort((left, right) => compareMapCategoryKeys(resolvedDimension, left.key, right.key)),
        };
    }, [features, resources]);

    if (entries.length === 0) return null;

    return (
        <div
            data-testid="portal-map-legend"
            className="absolute right-4 bottom-4 z-1000 max-h-50 overflow-y-auto rounded-lg border bg-background/90 px-3 py-2 text-xs shadow-md backdrop-blur-sm"
        >
            <div className="mb-1 font-semibold text-muted-foreground">{dimension === 'material' ? 'Material' : 'Resource Types'}</div>
            <div className="space-y-1">
                {entries.map((entry) => {
                    const materialStyle = dimension === 'material' ? getMaterialCategoryStyle(entry.key) : null;
                    const isMissingMaterial = dimension === 'material' && entry.key === 'missing';

                    return (
                        <div key={entry.key} className="flex items-center gap-2" data-testid={`legend-item-${entry.key}`}>
                            <span
                                aria-label={`${entry.label} map color`}
                                className={
                                    dimension === 'resource-type' && isIgsnType(entry.key)
                                        ? 'inline-block h-3 w-3 rotate-45 rounded-[1px] border border-white'
                                        : dimension === 'resource-type'
                                          ? 'relative inline-block h-3 w-3 rounded-full border'
                                          : 'relative inline-block h-3 w-3 rounded-[2px] border'
                                }
                                style={{
                                    backgroundColor: getMapCategoryColor(dimension, entry.key),
                                    borderColor: materialStyle?.textColor === '#FFFFFF' ? '#FFFFFF' : '#475569',
                                    borderStyle: isMissingMaterial ? 'dashed' : 'solid',
                                }}
                            >
                                {dimension === 'material' && entry.key === 'not-applicable' && (
                                    <span className="absolute top-1/2 right-0.5 left-0.5 h-px -translate-y-1/2 bg-slate-700" />
                                )}
                            </span>
                            <span>{entry.label}</span>
                            <span
                                className="ml-auto text-muted-foreground tabular-nums"
                                aria-label={`${entry.count} ${entry.count === 1 ? 'location' : 'locations'}`}
                            >
                                {entry.count.toLocaleString()}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
