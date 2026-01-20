'use client';

import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { TableHead } from '@/components/ui/table';
import { cn } from '@/lib/utils';

export type SortDirection = 'asc' | 'desc';

export interface SortState<T extends string = string> {
    key: T;
    direction: SortDirection;
}

interface SortableTableHeaderProps<T extends string = string> extends React.HTMLAttributes<HTMLTableCellElement> {
    /** Column label to display */
    label: React.ReactNode;
    /** Sort key for this column */
    sortKey: T;
    /** Current sort state */
    sortState: SortState<T>;
    /** Callback when sort is changed */
    onSort: (key: T) => void;
    /** Default direction when first clicking this column (default: 'asc') */
    defaultDirection?: SortDirection;
}

/**
 * Sortable table header component for use with standard HTML tables.
 * Works independently of TanStack Table - ideal for server-side sorted tables.
 *
 * @example
 * ```tsx
 * const [sort, setSort] = useState<SortState>({ key: 'name', direction: 'asc' });
 *
 * const handleSort = (key: string) => {
 *     setSort(prev => ({
 *         key,
 *         direction: prev.key === key && prev.direction === 'asc' ? 'desc' : 'asc'
 *     }));
 * };
 *
 * <TableHeader>
 *     <TableRow>
 *         <SortableTableHeader
 *             label="Name"
 *             sortKey="name"
 *             sortState={sort}
 *             onSort={handleSort}
 *         />
 *         <SortableTableHeader
 *             label="Created"
 *             sortKey="created_at"
 *             sortState={sort}
 *             onSort={handleSort}
 *             defaultDirection="desc"
 *         />
 *     </TableRow>
 * </TableHeader>
 * ```
 */
export function SortableTableHeader<T extends string = string>({
    label,
    sortKey,
    sortState,
    onSort,
    defaultDirection = 'asc',
    className,
    ...props
}: SortableTableHeaderProps<T>) {
    const isActive = sortState.key === sortKey;
    const currentDirection = isActive ? sortState.direction : defaultDirection;

    const ariaSort = isActive ? (sortState.direction === 'asc' ? 'ascending' : 'descending') : 'none';

    const handleClick = () => {
        onSort(sortKey);
    };

    return (
        <TableHead className={cn(className)} aria-sort={ariaSort} {...props}>
            <Button
                variant="ghost"
                size="sm"
                className="-ml-3 h-8 px-3 font-medium"
                onClick={handleClick}
                aria-label={`Sort by ${typeof label === 'string' ? label : sortKey}`}
            >
                <span>{label}</span>
                <SortIndicator isActive={isActive} direction={currentDirection} />
            </Button>
        </TableHead>
    );
}

interface SortIndicatorProps {
    isActive: boolean;
    direction: SortDirection;
}

function SortIndicator({ isActive, direction }: SortIndicatorProps) {
    if (!isActive) {
        return <ArrowUpDown aria-hidden="true" className="ml-2 size-3.5 opacity-50" />;
    }

    if (direction === 'asc') {
        return <ArrowUp aria-hidden="true" className="ml-2 size-3.5" />;
    }

    return <ArrowDown aria-hidden="true" className="ml-2 size-3.5" />;
}

/**
 * Hook for managing sort state with toggle behavior.
 * Provides a convenient way to handle sort state changes.
 *
 * @example
 * ```tsx
 * const { sortState, handleSort } = useSortState({
 *     initialKey: 'updated_at',
 *     initialDirection: 'desc',
 *     defaultDirections: {
 *         name: 'asc',
 *         created_at: 'desc',
 *         updated_at: 'desc',
 *     },
 *     onSortChange: (newSort) => {
 *         router.get('/items', { sort: newSort.key, direction: newSort.direction });
 *     },
 * });
 * ```
 */
interface UseSortStateOptions<T extends string = string> {
    /** Initial sort key */
    initialKey: T;
    /** Initial sort direction */
    initialDirection: SortDirection;
    /** Default directions per key (used when clicking a new column) */
    defaultDirections?: Partial<Record<T, SortDirection>>;
    /** Callback when sort changes */
    onSortChange?: (sort: SortState<T>) => void;
}

export function useSortState<T extends string = string>({
    initialKey,
    initialDirection,
    defaultDirections = {},
    onSortChange,
}: UseSortStateOptions<T>) {
    const [sortState, setSortState] = React.useState<SortState<T>>({
        key: initialKey,
        direction: initialDirection,
    });

    const handleSort = React.useCallback(
        (key: T) => {
            setSortState((prev) => {
                let newDirection: SortDirection;

                if (prev.key === key) {
                    // Toggle direction if same key
                    newDirection = prev.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    // Use default direction for new key
                    newDirection = defaultDirections[key] ?? 'asc';
                }

                const newSort = { key, direction: newDirection };
                onSortChange?.(newSort);
                return newSort;
            });
        },
        [defaultDirections, onSortChange],
    );

    return { sortState, handleSort, setSortState };
}

export { SortIndicator };
