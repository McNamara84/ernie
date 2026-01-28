import { Loader2, type LucideProps } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

type SpinnerSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

interface SpinnerProps extends Omit<LucideProps, 'ref'> {
    /** Size preset for the spinner */
    size?: SpinnerSize;
}

const sizeMap: Record<SpinnerSize, string> = {
    xs: 'h-3 w-3',
    sm: 'h-4 w-4',
    md: 'h-5 w-5',
    lg: 'h-6 w-6',
    xl: 'h-8 w-8',
};

/**
 * Unified loading spinner component.
 *
 * Uses Lucide Loader2 icon with consistent styling across the application.
 * Replaces various custom spinner implementations (CSS border spinners,
 * inline animate-spin classes, LoaderCircle icons).
 *
 * @example
 * // Default medium size
 * <Spinner />
 *
 * // Small spinner for buttons
 * <Spinner size="sm" />
 *
 * // Large spinner for page loading
 * <Spinner size="lg" />
 *
 * // With custom className
 * <Spinner className="text-primary" />
 */
const Spinner = React.forwardRef<SVGSVGElement, SpinnerProps>(({ className, size = 'md', ...props }, ref) => {
    return <Loader2 ref={ref} className={cn('animate-spin', sizeMap[size], className)} aria-hidden="true" {...props} />;
});
Spinner.displayName = 'Spinner';

export { Spinner, type SpinnerProps, type SpinnerSize };
