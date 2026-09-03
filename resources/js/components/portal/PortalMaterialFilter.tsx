import { ChevronDown, ChevronRight, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { PortalTreeFacet } from '@/types/portal';

interface MaterialNodeProps {
    node: PortalTreeFacet;
    level: number;
    selected: ReadonlySet<string>;
    expandedAncestors: ReadonlySet<string>;
    onToggle: (value: string) => void;
}

function MaterialNode({ node, level, selected, expandedAncestors, onToggle }: MaterialNodeProps) {
    const hasChildren = node.children.length > 0;
    const shouldExpand = expandedAncestors.has(node.value);
    const [expanded, setExpanded] = useState(shouldExpand);

    useEffect(() => {
        if (shouldExpand) setExpanded(true);
    }, [shouldExpand]);

    return (
        <li>
            <div
                className="flex min-h-8 items-center gap-2 rounded-md px-2 py-1 hover:bg-muted/60"
                style={{ paddingLeft: `${level * 1.25 + 0.5}rem` }}
            >
                {hasChildren ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-5 w-5 shrink-0"
                        onClick={() => setExpanded((value) => !value)}
                        aria-label={expanded ? `Collapse ${node.label}` : `Expand ${node.label}`}
                        aria-expanded={expanded}
                    >
                        {expanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                    </Button>
                ) : (
                    <span className="w-5 shrink-0" />
                )}
                <Checkbox checked={selected.has(node.value)} onCheckedChange={() => onToggle(node.value)} aria-label={`Select ${node.label}`} />
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => onToggle(node.value)}
                    className="h-auto min-w-0 flex-1 justify-start truncate rounded-none px-0 py-0 font-normal hover:bg-transparent"
                >
                    {node.label}
                </Button>
                <span className="text-xs text-muted-foreground tabular-nums">{node.count.toLocaleString('en-US')}</span>
            </div>
            {hasChildren && expanded && (
                <ul>
                    {node.children.map((child) => (
                        <MaterialNode
                            key={child.value}
                            node={child}
                            level={level + 1}
                            selected={selected}
                            expandedAncestors={expandedAncestors}
                            onToggle={onToggle}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

interface PortalMaterialFilterProps {
    facets: PortalTreeFacet[];
    selectedValues: string[];
    onSelectionChange: (values: string[]) => void;
}

export function PortalMaterialFilter({ facets, selectedValues, onSelectionChange }: PortalMaterialFilterProps) {
    const selected = useMemo(() => new Set(selectedValues), [selectedValues]);
    const { labels, expandedAncestors } = useMemo(() => {
        const nodeLabels = new Map<string, string>();
        const ancestors = new Set<string>();

        const visit = (node: PortalTreeFacet): boolean => {
            nodeLabels.set(node.value, node.label);
            let containsSelection = selected.has(node.value);
            for (const child of node.children) {
                if (visit(child)) {
                    ancestors.add(node.value);
                    containsSelection = true;
                }
            }
            return containsSelection;
        };

        facets.forEach(visit);
        return { labels: nodeLabels, expandedAncestors: ancestors };
    }, [facets, selected]);

    const toggle = (value: string) => {
        onSelectionChange(selected.has(value) ? selectedValues.filter((selectedValue) => selectedValue !== value) : [...selectedValues, value]);
    };

    return (
        <div className="space-y-3">
            {selectedValues.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {selectedValues.map((value) => (
                        <Badge key={value} variant="secondary" className="gap-1 pr-1 text-xs">
                            {labels.get(value) ?? value}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-4 w-4 p-0 hover:bg-transparent"
                                onClick={() => toggle(value)}
                                aria-label={`Remove ${labels.get(value) ?? value}`}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ))}
                </div>
            )}

            {facets.length === 0 ? (
                <p className="text-sm text-muted-foreground">No materials available.</p>
            ) : (
                <div className="rounded-lg border bg-background/80">
                    <ul aria-label="Materials" className="py-1">
                        {facets.map((node) => (
                            <MaterialNode
                                key={node.value}
                                node={node}
                                level={0}
                                selected={selected}
                                expandedAncestors={expandedAncestors}
                                onToggle={toggle}
                            />
                        ))}
                    </ul>
                </div>
            )}

            <p className="text-xs text-muted-foreground">Select one or more materials. Parent selections include their descendants.</p>
        </div>
    );
}
