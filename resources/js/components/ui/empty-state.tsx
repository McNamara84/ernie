import { Plus } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

import { Button } from './button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from './empty';

interface EmptyStateAction {
    label: string;
    onClick: () => void;
    icon?: React.ReactNode;
}

interface EmptyStateProps {
    /** Icon to display above the title */
    icon?: React.ReactNode;
    /** Main title text */
    title: string;
    /** Optional description text below the title */
    description?: string;
    /** Primary action button configuration */
    action?: EmptyStateAction;
    /** Secondary action button configuration */
    secondaryAction?: EmptyStateAction;
    /** Custom actions (e.g., dialogs) - replaces action/secondaryAction if provided */
    children?: React.ReactNode;
    /** Visual variant - default shows dashed border, compact is more minimal */
    variant?: 'default' | 'compact';
    /** Additional CSS classes */
    className?: string;
    /** Test ID for Playwright tests */
    'data-testid'?: string;
}

/**
 * EmptyState Component
 *
 * Displays a consistent empty state UI for sections without content.
 * Used across the editor for optional sections like Dates, Coverage,
 * Funding References, and Related Work.
 *
 * @example
 * ```tsx
 * <EmptyState
 *   icon={<Calendar className="h-8 w-8" />}
 *   title="No dates added"
 *   description="Add important dates like collection period or validity."
 *   action={{
 *     label: "Add Date",
 *     onClick: handleAddDate,
 *     icon: <Plus className="h-4 w-4" />
 *   }}
 * />
 * ```
 */
export function EmptyState({
    icon,
    title,
    description,
    action,
    secondaryAction,
    children,
    variant = 'default',
    className,
    'data-testid': dataTestId,
}: EmptyStateProps) {
    const isCompact = variant === 'compact';

    // Determine if we should show the built-in actions or custom children
    const hasBuiltInActions = action || secondaryAction;
    const showBuiltInActions = hasBuiltInActions && !children;

    return (
        <Empty
            className={cn(
                'flex-none gap-0 p-0 md:p-0',
                isCompact ? 'border-0 border-solid py-6' : 'border-2 border-dashed border-border bg-muted/30 px-4 py-8',
                className,
            )}
            data-testid={dataTestId}
            role="status"
            aria-label={title}
        >
            <EmptyHeader className="gap-0">
                {icon && (
                    <EmptyMedia className={cn('text-muted-foreground', isCompact ? 'mb-2' : 'mb-3')}>{icon}</EmptyMedia>
                )}
                <EmptyTitle className={cn('font-medium tracking-normal text-foreground', isCompact ? 'text-sm' : 'text-base')}>
                    {title}
                </EmptyTitle>
                {description && (
                    <EmptyDescription className={cn(isCompact ? 'mt-1 text-xs' : 'mt-2 max-w-md text-sm')}>
                        {description}
                    </EmptyDescription>
                )}
            </EmptyHeader>

            {showBuiltInActions && (
                <EmptyContent className={cn('flex-row gap-2', isCompact ? 'mt-3' : 'mt-4')}>
                    {action && (
                        <Button type="button" variant="outline" size={isCompact ? 'sm' : 'default'} onClick={action.onClick}>
                            {action.icon ?? <Plus className="mr-2 h-4 w-4" />}
                            {action.label}
                        </Button>
                    )}
                    {secondaryAction && (
                        <Button type="button" variant="ghost" size={isCompact ? 'sm' : 'default'} onClick={secondaryAction.onClick}>
                            {secondaryAction.icon}
                            {secondaryAction.label}
                        </Button>
                    )}
                </EmptyContent>
            )}

            {children && (
                <EmptyContent className={cn('flex-row gap-2', isCompact ? 'mt-3' : 'mt-4')}>{children}</EmptyContent>
            )}
        </Empty>
    );
}

export default EmptyState;
