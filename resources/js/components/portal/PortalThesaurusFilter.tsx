import { ChevronDown, ChevronRight, Network, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

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
    selectedNodeIds: string[];
    onToggleNode: (nodeId: string) => void;
}

function hasSelectedDescendant(node: VocabularyKeyword, selectedNodeIds: string[]): boolean {
    if (selectedNodeIds.includes(node.id)) {
        return true;
    }

    return node.children.some((child) => hasSelectedDescendant(child, selectedNodeIds));
}

function ThesaurusTreeNode({ node, level = 0, selectedNodeIds, onToggleNode }: ThesaurusTreeNodeProps) {
    const hasChildren = node.children.length > 0;
    const [isExpanded, setIsExpanded] = useState(level === 0 || hasSelectedDescendant(node, selectedNodeIds));
    const isSelected = selectedNodeIds.includes(node.id);

    useEffect(() => {
        if (hasSelectedDescendant(node, selectedNodeIds)) {
            setIsExpanded(true);
        }
    }, [node, selectedNodeIds]);

    return (
        <div>
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

                <button
                    type="button"
                    className={cn(
                        'min-w-0 flex-1 truncate text-left text-sm',
                        isSelected ? 'font-medium text-foreground' : 'text-muted-foreground',
                    )}
                    onClick={() => onToggleNode(node.id)}
                >
                    {node.text}
                </button>
            </div>

            {hasChildren && isExpanded && (
                <div>
                    {node.children.map((child) => (
                        <ThesaurusTreeNode
                            key={child.id}
                            node={child}
                            level={level + 1}
                            selectedNodeIds={selectedNodeIds}
                            onToggleNode={onToggleNode}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

export function PortalThesaurusFilter({ facets = [], selectedNodeIds = [], onSelectionChange = () => undefined }: PortalThesaurusFilterProps) {
    const toggleNode = useCallback(
        (nodeId: string) => {
            onSelectionChange(
                selectedNodeIds.includes(nodeId)
                    ? selectedNodeIds.filter((selectedId) => selectedId !== nodeId)
                    : [...selectedNodeIds, nodeId],
            );
        },
        [onSelectionChange, selectedNodeIds],
    );

    const selectedNodes = useMemo(() => {
        const labels = new Map<string, string>();

        const visit = (node: VocabularyKeyword) => {
            labels.set(node.id, node.text);
            node.children.forEach(visit);
        };

        facets.forEach((facet) => {
            facet.roots.forEach(visit);
        });

        return selectedNodeIds.map((nodeId) => ({
            id: nodeId,
            label: labels.get(nodeId) ?? nodeId,
        }));
    }, [facets, selectedNodeIds]);

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
                        const selectedCount = facet.roots.reduce((count, root) => {
                            const visit = (node: VocabularyKeyword): number => {
                                const ownCount = selectedNodeIds.includes(node.id) ? 1 : 0;
                                return ownCount + node.children.reduce((childCount, child) => childCount + visit(child), 0);
                            };

                            return count + visit(root);
                        }, 0);

                        return (
                            <div key={facet.scheme} className="rounded-lg border bg-background/80">
                                <div className="flex items-center justify-between border-b px-3 py-2">
                                    <span className="text-sm font-medium">{getSchemeLabel(facet.scheme)}</span>
                                    {selectedCount > 0 && <Badge variant="outline">{selectedCount} selected</Badge>}
                                </div>
                                <ScrollArea className="max-h-72">
                                    <div className="py-2">
                                        {facet.roots.map((root) => (
                                            <ThesaurusTreeNode
                                                key={root.id}
                                                node={root}
                                                selectedNodeIds={selectedNodeIds}
                                                onToggleNode={toggleNode}
                                            />
                                        ))}
                                    </div>
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