import type { VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { Button, type buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

interface LoadingButtonProps
    extends React.ComponentProps<'button'>,
        VariantProps<typeof buttonVariants> {
    /** When true, shows a spinner and disables the button */
    loading?: boolean;
    asChild?: boolean;
}

/**
 * Button with built-in loading state support.
 * Shows a spinner and disables interaction while `loading` is true.
 * Follows shadcn/ui v4 pattern — no forwardRef.
 */
function LoadingButton({
    children,
    loading = false,
    disabled,
    className,
    ...props
}: LoadingButtonProps) {
    return (
        <Button
            data-slot="loading-button"
            disabled={loading || disabled}
            aria-busy={loading}
            aria-disabled={loading || disabled || undefined}
            className={cn(className)}
            {...props}
        >
            {loading && <Spinner size="sm" />}
            {children}
        </Button>
    );
}

export { LoadingButton, type LoadingButtonProps };
