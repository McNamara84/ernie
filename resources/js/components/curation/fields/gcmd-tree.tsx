import { ChevronDown, ChevronRight } from 'lucide-react';
import { memo, useState } from 'react';

import { Checkbox } from '@/components/ui/checkbox';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import type { GCMDKeyword } from '@/types/gcmd';

interface GCMDTreeNodeProps {
    node: GCMDKeyword;
    selectedIds: Set<string>;
    onToggle: (keyword: GCMDKeyword, path: string[]) => void;
    level?: number;
    pathPrefix?: string[];
    searchQuery?: string; // For highlighting search terms
}

/**
 * Highlights search query within text
 */
function highlightText(text: string, query?: string): React.ReactNode {
    if (!query || query.length < 3) {
        return text;
    }

    const parts = text.split(new RegExp(`(${query})`, 'gi'));
    return parts.map((part, index) =>
        part.toLowerCase() === query.toLowerCase() ? (
            <mark key={index} className="bg-yellow-200 font-medium dark:bg-yellow-900/50">
                {part}
            </mark>
        ) : (
            part
        ),
    );
}

/**
 * Recursive tree node component for GCMD controlled vocabularies
 * Memoized to prevent unnecessary re-renders when parent updates
 */
const GCMDTreeNodeComponent = ({ node, selectedIds, onToggle, level = 0, pathPrefix = [], searchQuery }: GCMDTreeNodeProps) => {
    // Check if this node or any of its descendants are selected
    const hasSelectedDescendant = (keyword: GCMDKeyword): boolean => {
        if (selectedIds.has(keyword.id)) return true;
        if (!keyword.children || keyword.children.length === 0) return false;
        return keyword.children.some(hasSelectedDescendant);
    };

    // Auto-expand if:
    // 1. Root level (level 0) for initial visibility
    // 2. Node or any descendant is selected (to show selected keywords)
    const shouldAutoExpand = level === 0 || hasSelectedDescendant(node);
    const [isExpanded, setIsExpanded] = useState(shouldAutoExpand);

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
                    'group flex items-center gap-2 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50',
                    level === 0 && 'font-semibold',
                )}
                style={{ paddingLeft: `${level * 1.5 + 0.5}rem` }}
            >
                {/* Expand/Collapse Icon */}
                <button
                    type="button"
                    onClick={handleExpand}
                    className={cn('flex h-4 w-4 flex-shrink-0 items-center justify-center', !hasChildren && 'invisible')}
                    aria-label={isExpanded ? 'Collapse' : 'Expand'}
                >
                    {hasChildren &&
                        (isExpanded ? (
                            <ChevronDown className="h-4 w-4 text-muted-foreground" />
                        ) : (
                            <ChevronRight className="h-4 w-4 text-muted-foreground" />
                        ))}
                </button>

                {/* Checkbox */}
                <Checkbox id={`gcmd-node-${node.id}`} checked={isSelected} onCheckedChange={handleToggle} className="flex-shrink-0" />

                {/* Label */}
                <label htmlFor={`gcmd-node-${node.id}`} className="flex-1 cursor-pointer text-sm leading-tight" title={node.description || node.text}>
                    {highlightText(node.text, searchQuery)}
                </label>
            </div>

            {/* Children */}
            {hasChildren && isExpanded && (
                <div className="mt-0.5">
                    {node.children.map((child) => (
                        <GCMDTreeNodeComponent
                            key={child.id}
                            node={child}
                            selectedIds={selectedIds}
                            onToggle={onToggle}
                            level={level + 1}
                            pathPrefix={currentPath}
                            searchQuery={searchQuery}
                        />
                    ))}
                </div>
            )}
        </div>
    );
};

// Memoize the component to prevent re-renders when props haven't changed
export const GCMDTreeNode = memo(GCMDTreeNodeComponent, (prevProps, nextProps) => {
    // Custom comparison function for better performance
    return prevProps.node.id === nextProps.node.id && prevProps.selectedIds === nextProps.selectedIds && prevProps.level === nextProps.level;
});

interface GCMDTreeProps {
    keywords: GCMDKeyword[];
    selectedIds: Set<string>;
    onToggle: (keyword: GCMDKeyword, path: string[]) => void;
    emptyMessage?: string;
    searchQuery?: string; // For highlighting search terms
}

/**
 * Tree view for GCMD controlled vocabularies
 */
export function GCMDTree({ keywords, selectedIds, onToggle, emptyMessage, searchQuery }: GCMDTreeProps) {
    if (!keywords || keywords.length === 0) {
        return <div className="py-8 text-center text-sm text-muted-foreground">{emptyMessage || 'No keywords available'}</div>;
    }

    return (
        <ScrollArea className="h-96 rounded-md border bg-muted/10">
            <div className="space-y-1 p-2">
                {keywords.map((keyword) => (
                    <GCMDTreeNode key={keyword.id} node={keyword} selectedIds={selectedIds} onToggle={onToggle} searchQuery={searchQuery} />
                ))}
            </div>
        </ScrollArea>
    );
}
