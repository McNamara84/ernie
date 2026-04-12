import { ChevronDown } from 'lucide-react';
import { type ReactNode, useId, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { cn } from '@/lib/utils';

const DEFAULT_THRESHOLD = 10;

interface CollapsibleListProps<T> {
    /** All items to render */
    items: T[];
    /** Render function for each item */
    renderItem: (item: T, index: number) => ReactNode;
    /** Number of items above which the list is collapsible (defaults to 10) */
    threshold?: number;
    /** Label for the expand/collapse button (e.g. "contributors") */
    itemLabel: string;
    /** Optional wrapper around the rendered items (e.g. a `<ul>` element). Receives the visible items as children. */
    wrapper?: (children: ReactNode) => ReactNode;
    className?: string;
}

/**
 * Data-driven collapsible list that renders only the first `threshold` items
 * when collapsed. Avoids CSS height measurement — works reliably with any
 * layout (flex-wrap, grid, variable-height items, multiple containers).
 *
 * SSR-safe: no client-side measurement needed, and the collapsed set of items
 * is rendered identically on server and client.
 */
export function CollapsibleList<T>({ items, renderItem, threshold = DEFAULT_THRESHOLD, itemLabel, wrapper, className }: CollapsibleListProps<T>) {
    const [isExpanded, setIsExpanded] = useState(false);
    const regionId = useId();
    const reducedMotion = useReducedMotion();

    if (items.length <= threshold) {
        const rendered = items.map((item, i) => renderItem(item, i));
        return <div className={className}>{wrapper ? wrapper(rendered) : rendered}</div>;
    }

    const visibleItems = isExpanded ? items : items.slice(0, threshold);
    const rendered = visibleItems.map((item, i) => renderItem(item, i));

    return (
        <div className={className}>
            <div id={regionId} role="region" aria-label={`${itemLabel} list`}>
                {wrapper ? wrapper(rendered) : rendered}
            </div>

            <Button
                variant="ghost"
                size="sm"
                onClick={() => setIsExpanded((prev) => !prev)}
                aria-expanded={isExpanded}
                aria-controls={regionId}
                className={cn(
                    'mt-2 gap-1 text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300',
                    !reducedMotion && 'transition-colors duration-200',
                )}
            >
                <ChevronDown
                    className={cn('h-4 w-4', !reducedMotion && 'transition-transform duration-200', isExpanded && 'rotate-180')}
                    aria-hidden="true"
                />
                {isExpanded ? `Show fewer ${itemLabel}` : `Show all ${items.length} ${itemLabel}`}
            </Button>
        </div>
    );
}
