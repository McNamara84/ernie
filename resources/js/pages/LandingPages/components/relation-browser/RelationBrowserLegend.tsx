import {
    EDGE_FALLBACK_COLOR,
    GFZ_BLUE,
    getEdgeCategoryColorMap,
    getEdgeCategory,
    getEdgeColor,
    getNodeColor,
    getNodeColorMap,
    NODE_FALLBACK_COLOR,
} from './graph-colors';

interface RelationBrowserLegendProps {
    activeIdentifierTypes: string[];
    activeRelationTypes: string[];
}

export function RelationBrowserLegend({ activeIdentifierTypes, activeRelationTypes }: RelationBrowserLegendProps) {
    const nodeColorMap = getNodeColorMap();
    const edgeCategoryColorMap = getEdgeCategoryColorMap();

    // Deduplicate relation types by category
    const activeCategories = [...new Set(activeRelationTypes.map((rt) => getEdgeCategory(rt)))];

    const hasNodeTypes = activeIdentifierTypes.length > 0;
    const hasEdgeTypes = activeCategories.length > 0;

    if (!hasNodeTypes && !hasEdgeTypes) {
        return null;
    }

    return (
        <div
            data-testid="relation-browser-legend"
            className="flex flex-wrap items-start gap-6 border-t border-gray-200 bg-gray-50/50 px-4 py-3"
        >
            {/* Central resource */}
            <div className="flex items-center gap-4">
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-500">Resource</span>
                <div className="flex items-center gap-1.5">
                    <span
                        className="inline-block h-3 w-3 rounded-full"
                        style={{ backgroundColor: GFZ_BLUE }}
                    />
                    <span className="text-xs text-gray-600">This Resource</span>
                </div>
            </div>

            {/* Node colors by identifier type */}
            {hasNodeTypes && (
                <div className="flex flex-wrap items-center gap-4">
                    <span className="text-xs font-semibold uppercase tracking-wider text-gray-500">Identifier Types</span>
                    {activeIdentifierTypes.map((type) => (
                        <div key={type} className="flex items-center gap-1.5">
                            <span
                                className="inline-block h-3 w-3 rounded-full"
                                style={{ backgroundColor: getNodeColor(type, false) }}
                                data-testid={`legend-node-${type}`}
                            />
                            <span className="text-xs text-gray-600">{type}</span>
                        </div>
                    ))}
                </div>
            )}

            {/* Edge colors by relation type category */}
            {hasEdgeTypes && (
                <div className="flex flex-wrap items-center gap-4">
                    <span className="text-xs font-semibold uppercase tracking-wider text-gray-500">Relation Types</span>
                    {activeCategories.map((category) => {
                        const color = category === 'Other'
                            ? EDGE_FALLBACK_COLOR
                            : (edgeCategoryColorMap[category] ?? EDGE_FALLBACK_COLOR);
                        return (
                            <div key={category} className="flex items-center gap-1.5">
                                <span
                                    className="inline-block h-0.5 w-4 rounded"
                                    style={{ backgroundColor: color }}
                                    data-testid={`legend-edge-${category}`}
                                />
                                <span className="text-xs text-gray-600">{category}</span>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
