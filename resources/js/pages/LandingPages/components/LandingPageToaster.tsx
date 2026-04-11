import { Toaster as Sonner, type ToasterProps } from 'sonner';

/**
 * Landing-page-specific Toaster wrapper.
 *
 * Uses the same classNames/toastOptions as the main shadcn/ui wrapper
 * (`@/components/ui/sonner`) for consistent toast styling, but does NOT
 * call `useAppearance()`.  This avoids mutating `document.documentElement`
 * via localStorage/cookies, which would conflict with the system-only
 * dark mode applied by `useSystemDarkMode()`.
 *
 * The `theme` prop must be passed explicitly by the landing page
 * (derived from `useSystemDarkMode()`).
 */
export function LandingPageToaster(props: ToasterProps) {
    return (
        <Sonner
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
}
