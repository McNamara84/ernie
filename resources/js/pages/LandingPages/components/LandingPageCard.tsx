import type { ComponentProps, ReactNode } from 'react';

import { cn } from '@/lib/utils';

import { useFadeInOnScroll } from '../hooks/useFadeInOnScroll';

interface LandingPageCardProps extends ComponentProps<'section'> {
    children: ReactNode;
    /** When true, the card is immediately visible without scroll-triggered fade-in. */
    disableFadeIn?: boolean;
}

/**
 * Shared card wrapper for landing page sections.
 *
 * Provides consistent visual styling (border, shadow, dark mode) and
 * integrates the scroll-triggered fade-in animation via CSS class.
 */
export function LandingPageCard({ className, children, disableFadeIn = false, ...props }: LandingPageCardProps) {
    const fadeRef = useFadeInOnScroll();

    return (
        <section
            ref={disableFadeIn ? undefined : fadeRef}
            data-slot="landing-page-card"
            className={cn(
                'rounded-lg border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md dark:border-gray-700 dark:bg-gray-800',
                !disableFadeIn && 'fade-in-on-scroll',
                className,
            )}
            {...props}
        >
            {children}
        </section>
    );
}
