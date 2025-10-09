import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';

import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import type { GCMDKeyword } from '@/types/gcmd';

interface GCMDTreeNodeProps {
    node: GCMDKeyword;
    selectedIds: Set<string>;
    onToggle: (keyword: GCMDKeyword, path: string[]) => void;
    level?: number;
    pathPrefix?: string[];
}

/**
 * Recursive tree node component for GCMD controlled vocabularies
 */
export function GCMDTreeNode({
    node,
    selectedIds,
    onToggle,
    level = 0,
    pathPrefix = [],
}: GCMDTreeNodeProps) {
    const [isExpanded, setIsExpanded] = useState(level < 2); // Auto-expand first 2 levels
    const hasChildren = node.children && node.children.length > 0;
    const isSelected = selectedIds.has(node.id);
    const currentPath = [...pathPrefix, node.text];

    const handleToggle = () => {
        onToggle(node, currentPath);
    };

    const handleExpand = () => {
        if (hasChildren) {
            setIsExpanded(!isExpanded);
        }
    };

    return (
        <div className="select-none">
            <div
                className={cn(
                    'flex items-center gap-2 py-1.5 px-2 rounded-md hover:bg-accent/50 transition-colors group',
                    level === 0 && 'font-semibold',
                )}
                style={{ paddingLeft: `${level * 1.5 + 0.5}rem` }}
            >
                {/* Expand/Collapse Icon */}
                <button
                    type="button"
                    onClick={handleExpand}
                    className={cn(
                        'flex-shrink-0 w-4 h-4 flex items-center justify-center',
                        !hasChildren && 'invisible',
                    )}
                    aria-label={isExpanded ? 'Collapse' : 'Expand'}
                >
                    {hasChildren &&
                        (isExpanded ? (
                            <ChevronDown className="w-4 h-4 text-muted-foreground" />
                        ) : (
                            <ChevronRight className="w-4 h-4 text-muted-foreground" />
                        ))}
                </button>

                {/* Checkbox */}
                <Checkbox
                    id={`gcmd-node-${node.id}`}
                    checked={isSelected}
                    onCheckedChange={handleToggle}
                    className="flex-shrink-0"
                />

                {/* Label */}
                <label
                    htmlFor={`gcmd-node-${node.id}`}
                    className="flex-1 cursor-pointer text-sm leading-tight"
                    title={node.description || node.text}
                >
                    {node.text}
                </label>
            </div>

            {/* Children */}
            {hasChildren && isExpanded && (
                <div className="mt-0.5">
                    {node.children.map((child) => (
                        <GCMDTreeNode
                            key={child.id}
                            node={child}
                            selectedIds={selectedIds}
                            onToggle={onToggle}
                            level={level + 1}
                            pathPrefix={currentPath}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

interface GCMDTreeProps {
    keywords: GCMDKeyword[];
    selectedIds: Set<string>;
    onToggle: (keyword: GCMDKeyword, path: string[]) => void;
    emptyMessage?: string;
}

/**
 * Tree view for GCMD controlled vocabularies
 */
export function GCMDTree({ keywords, selectedIds, onToggle, emptyMessage }: GCMDTreeProps) {
    if (!keywords || keywords.length === 0) {
        return (
            <div className="text-sm text-muted-foreground text-center py-8">
                {emptyMessage || 'No keywords available'}
            </div>
        );
    }

    return (
        <div className="space-y-1 max-h-96 overflow-y-auto border rounded-md p-2 bg-muted/10">
            {keywords.map((keyword) => (
                <GCMDTreeNode
                    key={keyword.id}
                    node={keyword}
                    selectedIds={selectedIds}
                    onToggle={onToggle}
                />
            ))}
        </div>
    );
}
