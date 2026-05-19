import { ChevronDown, ChevronRight, Network, X } from 'lucide-react';
import { useCallback, useEffect, useId, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { getSchemeLabel } from '@/lib/keyword-schemes';
import { cn } from '@/lib/utils';
import type { PortalThesaurusFacet } from '@/types/portal';
import type { VocabularyKeyword } from '@/types/vocabulary';

interface PortalThesaurusFilterProps {
    facets?: PortalThesaurusFacet[];
    selectedNodeIds?: string[];
    onSelectionChange?: (nodeIds: string[]) => void;
}

interface ThesaurusTreeNodeProps {
    node: VocabularyKeyword;
    level?: number;
    expandedAncestorIds: ReadonlySet<string>;
    selectedNodeIdSet: ReadonlySet<string>;
    onToggleNode: (nodeId: string) => void;
}

function noopSelectionChange(): void {}

function ThesaurusTreeNode({ node, level = 0, expandedAncestorIds, selectedNodeIdSet, onToggleNode }: ThesaurusTreeNodeProps) {
    const hasChildren = node.children.length > 0;
    const shouldExpand = level === 0 || expandedAncestorIds.has(node.id);
    const [isExpanded, setIsExpanded] = useState(shouldExpand);
    const isSelected = selectedNodeIdSet.has(node.id);
    const labelId = useId();

    useEffect(() => {
        if (shouldExpand) {
            setIsExpanded(true);
        }
    }, [shouldExpand]);

    return (
        <li role="treeitem" aria-level={level + 1} aria-expanded={hasChildren ? isExpanded : undefined} aria-selected={isSelected} aria-labelledby={labelId}>
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
                        onClick={() => setIsExpanded((value) => !value)}
                        aria-label={isExpanded ? `Collapse ${node.text}` : `Expand ${node.text}`}
                    >
                        {isExpanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                    </Button>
                ) : (
                    <span className="w-5 shrink-0" />
                )}

                <Checkbox
                    checked={isSelected}
                    onCheckedChange={() => onToggleNode(node.id)}
                    aria-label={`Select thesaurus keyword ${node.text}`}
                />

                <Button
                    type="button"
                    variant="ghost"
                    className={cn(
                        'h-auto min-w-0 flex-1 justify-start px-0 py-0 text-left text-sm hover:bg-transparent',
                        isSelected ? 'font-medium text-foreground' : 'text-muted-foreground',
                    )}
                    onClick={() => onToggleNode(node.id)}
                >
                    <span id={labelId} className="block min-w-0 truncate">{node.text}</span>
                </Button>
            </div>

            {hasChildren && isExpanded && (
                <ul role="group">
                    {node.children.map((child) => (
                        <ThesaurusTreeNode
                            key={child.id}
                            node={child}
                            level={level + 1}
                            expandedAncestorIds={expandedAncestorIds}
                            selectedNodeIdSet={selectedNodeIdSet}
                            onToggleNode={onToggleNode}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

export function PortalThesaurusFilter({ facets = [], selectedNodeIds = [], onSelectionChange = noopSelectionChange }: PortalThesaurusFilterProps) {
    const selectedNodeIdSet = useMemo(() => new Set(selectedNodeIds), [selectedNodeIds]);

    const toggleNode = useCallback(
        (nodeId: string) => {
            onSelectionChange(
                selectedNodeIdSet.has(nodeId)
                    ? selectedNodeIds.filter((selectedId) => selectedId !== nodeId)
                    : [...selectedNodeIds, nodeId],
            );
        },
        [onSelectionChange, selectedNodeIdSet, selectedNodeIds],
    );

    const { expandedAncestorIds, selectedCountByScheme, selectedNodes } = useMemo(() => {
        const labels = new Map<string, string>();
        const expandedAncestors = new Set<string>();
        const countsByScheme = new Map<string, number>();

        facets.forEach((facet) => {
            let selectedCount = 0;

            const visitWithCount = (node: VocabularyKeyword): boolean => {
                labels.set(node.id, node.text);

                let hasSelectedNode = selectedNodeIdSet.has(node.id);
                if (hasSelectedNode) {
                    selectedCount += 1;
                }

                for (const child of node.children) {
                    if (visitWithCount(child)) {
                        expandedAncestors.add(node.id);
                        hasSelectedNode = true;
                    }
                }

                return hasSelectedNode;
            };

            facet.roots.forEach(visitWithCount);
            countsByScheme.set(facet.scheme, selectedCount);
        });

        return {
            expandedAncestorIds: expandedAncestors,
            selectedCountByScheme: countsByScheme,
            selectedNodes: selectedNodeIds.map((nodeId) => ({
                id: nodeId,
                label: labels.get(nodeId) ?? nodeId,
            })),
        };
    }, [facets, selectedNodeIdSet, selectedNodeIds]);

    return (
        <div className="space-y-3">
            <Label className="text-sm font-medium">
                <span className="flex items-center gap-1.5">
                    <Network className="h-3.5 w-3.5" />
                    Thesaurus Keywords
                </span>
            </Label>

            {selectedNodes.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {selectedNodes.map((node) => (
                        <Badge key={node.id} variant="secondary" className="gap-1 pr-1 text-xs">
                            {node.label}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-4 w-4 p-0 hover:bg-transparent"
                                onClick={() => toggleNode(node.id)}
                                aria-label={`Remove thesaurus keyword ${node.label}`}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ))}
                </div>
            )}

            {facets.length === 0 ? (
                <p className="text-sm text-muted-foreground">No thesaurus keywords available.</p>
            ) : (
                <div className="space-y-2">
                    {facets.map((facet) => {
                        const selectedCount = selectedCountByScheme.get(facet.scheme) ?? 0;

                        return (
                            <div key={facet.scheme} className="rounded-lg border bg-background/80">
                                <div className="flex items-center justify-between border-b px-3 py-2">
                                    <span className="text-sm font-medium">{getSchemeLabel(facet.scheme)}</span>
                                    {selectedCount > 0 && <Badge variant="outline">{selectedCount} selected</Badge>}
                                </div>
                                <ScrollArea className="max-h-72">
                                    <ul role="tree" aria-label={`${getSchemeLabel(facet.scheme)} thesaurus hierarchy`} className="py-2">
                                        {facet.roots.map((root) => (
                                            <ThesaurusTreeNode
                                                key={root.id}
                                                node={root}
                                                expandedAncestorIds={expandedAncestorIds}
                                                selectedNodeIdSet={selectedNodeIdSet}
                                                onToggleNode={toggleNode}
                                            />
                                        ))}
                                    </ul>
                                </ScrollArea>
                            </div>
                        );
                    })}
                </div>
            )}

            <p className="text-xs text-muted-foreground">Select hierarchical thesaurus terms. Parent selections include matching descendants.</p>
        </div>
    );
}