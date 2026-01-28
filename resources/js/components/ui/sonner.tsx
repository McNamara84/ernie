import { Toaster as Sonner, type ToasterProps } from 'sonner';

import { useAppearance } from '@/hooks/use-appearance';

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
            toastOptions={{
                classNames: {
                    toast: 'group toast group-[.toaster]:bg-background group-[.toaster]:text-foreground group-[.toaster]:border-border group-[.toaster]:shadow-lg',
                    description: 'group-[.toast]:text-muted-foreground',
                    actionButton: 'group-[.toast]:bg-primary group-[.toast]:text-primary-foreground',
                    cancelButton: 'group-[.toast]:bg-muted group-[.toast]:text-muted-foreground',
                },
            }}
            {...props}
        />
    );
};

export { Toaster };
