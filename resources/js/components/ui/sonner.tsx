import { Toaster as Sonner, type ToasterProps } from 'sonner';

import { useAppearance } from '@/hooks/use-appearance';

/**
 * Shared toast classNames used by both the main Toaster and LandingPageToaster.
 * Kept in one place so styling changes propagate automatically.
 */
export const toastClassNames = {
    toast: 'group toast group-[.toaster]:bg-background group-[.toaster]:text-foreground group-[.toaster]:border-border group-[.toaster]:shadow-lg',
    description: 'group-[.toast]:text-muted-foreground',
    actionButton: 'group-[.toast]:bg-primary group-[.toast]:text-primary-foreground',
    cancelButton: 'group-[.toast]:bg-muted group-[.toast]:text-muted-foreground',
} as const;

/**
 * shadcn/ui Toaster wrapper for Sonner.
 * Automatically integrates with the theme system.
 *
 * @see https://ui.shadcn.com/docs/components/sonner
 */
const Toaster = ({ ...props }: ToasterProps) => {
    const { appearance } = useAppearance();

    // Map 'system' to undefined to let Sonner handle it
    const theme = appearance === 'system' ? undefined : appearance;

    return (
        <Sonner
            theme={theme}
            className="toaster group"
            toastOptions={{ classNames: toastClassNames }}
            {...props}
        />
    );
};

export { Toaster };
