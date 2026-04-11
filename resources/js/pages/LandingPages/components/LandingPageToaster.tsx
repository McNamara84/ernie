import { Toaster as Sonner, type ToasterProps } from 'sonner';

import { toastClassNames } from '@/components/ui/sonner';

/**
 * Landing-page-specific Toaster wrapper.
 *
 * Re-uses the shared `toastClassNames` from the main shadcn/ui wrapper
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
            toastOptions={{ classNames: toastClassNames }}
            {...props}
        />
    );
}
