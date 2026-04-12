import { ChevronDown } from 'lucide-react';
import { useId, useState } from 'react';

import { cn } from '@/lib/utils';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

const DEFAULT_THRESHOLD = 10;

interface CollapsibleListProps {
    /** Total number of items in the list */
    itemCount: number;
    /** Number of items shown when collapsed (defaults to 10) */
    threshold?: number;
    /** The full list content */
    children: React.ReactNode;
    /** Label for the expand/collapse button (e.g. "contributors") */
    itemLabel: string;
    className?: string;
}

/**
 * Wraps a list and collapses it when the item count exceeds a threshold.
 * Uses CSS max-height transition for smooth expand/collapse animation.
 * Respects prefers-reduced-motion for accessibility.
 */
export function CollapsibleList({ itemCount, threshold = DEFAULT_THRESHOLD, children, itemLabel, className }: CollapsibleListProps) {
    const [isExpanded, setIsExpanded] = useState(false);
    const regionId = useId();
    const reducedMotion = useReducedMotion();

    if (itemCount <= threshold) {
        return <div className={className}>{children}</div>;
    }

    return (
        <div className={className}>
            <div
                id={regionId}
                role="region"
                aria-label={`${itemLabel} list`}
                className={cn(
                    'overflow-hidden',
                    !reducedMotion && 'transition-[max-height] duration-300 ease-in-out',
                )}
                style={{
                    maxHeight: isExpanded ? `${itemCount * 4}rem` : `${threshold * 2.5}rem`,
                }}
            >
                {children}
            </div>

            <button
                type="button"
                onClick={() => setIsExpanded((prev) => !prev)}
                aria-expanded={isExpanded}
                aria-controls={regionId}
                className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
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
            </button>
        </div>
    );
}
