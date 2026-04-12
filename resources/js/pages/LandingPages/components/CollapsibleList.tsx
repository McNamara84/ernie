import { ChevronDown } from 'lucide-react';
import { type ReactNode, useId, useLayoutEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { cn } from '@/lib/utils';

const DEFAULT_THRESHOLD = 10;

interface CollapsibleListProps {
    /** Total number of items in the list */
    itemCount: number;
    /** Number of items above which the list is collapsible (defaults to 10) */
    threshold?: number;
    /** The full list content */
    children: ReactNode;
    /** Label for the expand/collapse button (e.g. "contributors") */
    itemLabel: string;
    className?: string;
}

/**
 * Wraps a list and collapses it when the item count exceeds a threshold.
 * Uses measured `scrollHeight` for accurate expand/collapse animation
 * regardless of individual item height.
 * Respects prefers-reduced-motion for accessibility.
 */
export function CollapsibleList({ itemCount, threshold = DEFAULT_THRESHOLD, children, itemLabel, className }: CollapsibleListProps) {
    const [isExpanded, setIsExpanded] = useState(false);
    const regionId = useId();
    const reducedMotion = useReducedMotion();
    const contentRef = useRef<HTMLDivElement>(null);
    // Start at 0 to stay collapsed before measurement (avoids flash)
    const [collapsedHeight, setCollapsedHeight] = useState(0);
    const [fullHeight, setFullHeight] = useState(0);

    /**
     * Measures collapsed and full heights in the layout phase (before paint)
     * so the component renders collapsed from the very first frame.
     * Looks for direct children of a `ul/ol/[role="list"]` inside the region,
     * or falls back to the region's own direct children.
     */
    useLayoutEffect(() => {
        const node = contentRef.current;
        if (!node || itemCount <= threshold) return;

        const listEl = node.querySelector('ul, ol, [role="list"]') ?? node;
        const items = Array.from(listEl.children);

        setFullHeight(node.scrollHeight);

        if (items.length <= threshold) {
            // Can't measure per-item; use full height
            setCollapsedHeight(node.scrollHeight);
            return;
        }

        let height = 0;
        for (let i = 0; i < Math.min(threshold, items.length); i++) {
            const itemEl = items[i] as HTMLElement;
            const style = getComputedStyle(itemEl);
            height += itemEl.offsetHeight + parseFloat(style.marginTop) + parseFloat(style.marginBottom);
        }
        setCollapsedHeight(height);
    }, [itemCount, threshold, children]);

    if (itemCount <= threshold) {
        return <div className={className}>{children}</div>;
    }

    const maxHeight = isExpanded
        ? `${fullHeight}px`
        : `${collapsedHeight}px`;

    return (
        <div className={className}>
            <div
                ref={contentRef}
                id={regionId}
                role="region"
                aria-label={`${itemLabel} list`}
                className={cn(
                    'overflow-hidden',
                    !reducedMotion && 'transition-[max-height] duration-300 ease-in-out',
                )}
                style={{ maxHeight }}
            >
                {children}
            </div>

            <Button
                variant="ghost"
                size="sm"
                onClick={() => setIsExpanded((prev) => !prev)}
                aria-expanded={isExpanded}
                aria-controls={regionId}
                className="mt-2 gap-1 text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
            >
                <ChevronDown
                    className={cn(
                        'h-4 w-4',
                        !reducedMotion && 'transition-transform duration-200',
                        isExpanded && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
                {isExpanded
                    ? `Show fewer ${itemLabel}`
                    : `Show all ${itemCount} ${itemLabel}`}
            </Button>
        </div>
    );
}
