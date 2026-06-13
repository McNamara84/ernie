import { HelpCircle } from 'lucide-react';
import * as React from 'react';

import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

interface SectionCounter {
    current: number;
    max: number;
}

interface SectionHeaderProps {
    /** Main label text for the section */
    label: string;
    /** Short description displayed below the label in muted color */
    description?: string;
    /** Tooltip content shown when hovering the help icon */
    tooltip?: string;
    /** Whether the section contains required fields */
    required?: boolean;
    /** Counter showing current/max items (e.g., "3 / 10") */
    counter?: SectionCounter;
    /** Action buttons (e.g., CSV Import) aligned to the right */
    actions?: React.ReactNode;
    /** Additional CSS classes for the container */
    className?: string;
    /** ID for the label element (useful for aria-labelledby) */
    id?: string;
    /** Test ID for Playwright tests */
    'data-testid'?: string;
}

interface AccordionSectionHeaderProps {
    /** Main label text for the section */
    label: string;
    /** Short description displayed below the label in muted color */
    description?: string;
    /** Whether the section contains required fields */
    required?: boolean;
    /** Counter showing current/max items (e.g., "3 / 10") */
    counter?: SectionCounter;
    /** Validation/completion indicator shown next to the title */
    status?: React.ReactNode;
    /** Optional badge shown next to the title, e.g. "EPOS/MSL" */
    badge?: React.ReactNode;
    /** Additional CSS classes for the container */
    className?: string;
    /** ID for the label text (useful for aria-labelledby) */
    id?: string;
    /** Test ID for Playwright/Vitest tests */
    'data-testid'?: string;
}

interface SectionHelpActionProps {
    /** Section label used for the help button accessible name */
    label: string;
    /** Tooltip content shown when hovering/focusing the help icon */
    tooltip?: string;
}

export function SectionHelpAction({ label, tooltip }: SectionHelpActionProps) {
    if (!tooltip) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center justify-center rounded-sm text-muted-foreground ring-offset-background hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    aria-label={`Help for ${label}`}
                >
                    <HelpCircle className="h-4 w-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs">
                {tooltip}
            </TooltipContent>
        </Tooltip>
    );
}

export function AccordionSectionHeader({
    label,
    description,
    required,
    counter,
    status,
    badge,
    className,
    id,
    'data-testid': dataTestId,
}: AccordionSectionHeaderProps) {
    return (
        <div className={cn('min-w-0 flex-1 space-y-1', className)} data-testid={dataTestId}>
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span id={id} className="text-sm font-semibold">
                    {label}
                    {required && (
                        <span className="ml-0.5 font-bold text-destructive" aria-label="Required">
                            *
                        </span>
                    )}
                </span>

                {counter && (
                    <span className="text-sm font-normal text-muted-foreground">
                        ({counter.current} / {counter.max})
                    </span>
                )}

                {badge}

                {status && <span className="inline-flex shrink-0 items-center">{status}</span>}
            </div>

            {description && <p className="text-sm leading-snug font-normal text-muted-foreground">{description}</p>}
        </div>
    );
}

/**
 * SectionHeader Component
 *
 * Provides a consistent header layout for editor sections.
 * Includes label, description, optional tooltip, counter, and action buttons.
 *
 * @example
 * ```tsx
 * <SectionHeader
 *   label="Funding References"
 *   description="Grant and funder information"
 *   tooltip="Add information about research grants that funded this work"
 *   counter={{ current: 2, max: 10 }}
 *   actions={
 *     <Button variant="outline" size="sm">
 *       <Upload className="mr-2 h-4 w-4" />
 *       CSV Import
 *     </Button>
 *   }
 * />
 * ```
 */
export function SectionHeader({
    label,
    description,
    tooltip,
    required,
    counter,
    actions,
    className,
    id,
    'data-testid': dataTestId,
}: SectionHeaderProps) {
    return (
        <div className={cn('mb-4 space-y-1', className)} data-testid={dataTestId}>
            {/* Top row: Label, tooltip, counter, actions */}
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <Label id={id} className="text-base font-semibold" data-slot="label">
                        {label}
                        {required && (
                            <span className="ml-0.5 font-bold text-destructive" aria-label="Required">
                                *
                            </span>
                        )}
                    </Label>

                    <SectionHelpAction label={label} tooltip={tooltip} />

                    {counter && (
                        <span className="text-sm text-muted-foreground">
                            ({counter.current} / {counter.max})
                        </span>
                    )}
                </div>

                {actions && <div className="flex items-center gap-2">{actions}</div>}
            </div>

            {/* Description row */}
            {description && <p className="text-sm text-muted-foreground">{description}</p>}
        </div>
    );
}

export default SectionHeader;
