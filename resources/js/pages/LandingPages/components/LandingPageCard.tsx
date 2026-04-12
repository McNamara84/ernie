import { cn } from '@/lib/utils';

import { useFadeInOnScroll } from '../hooks/useFadeInOnScroll';

interface LandingPageCardProps extends React.ComponentProps<'section'> {
    children: React.ReactNode;
}

/**
 * Shared card wrapper for landing page sections.
 *
 * Provides consistent visual styling (border, shadow, dark mode) and
 * integrates the scroll-triggered fade-in animation via CSS class.
 */
export function LandingPageCard({ className, children, ...props }: LandingPageCardProps) {
    const fadeRef = useFadeInOnScroll();

    return (
        <section
            ref={fadeRef}
            data-slot="landing-page-card"
            className={cn(
                'fade-in-on-scroll rounded-lg border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md dark:border-gray-700 dark:bg-gray-800',
                className,
            )}
            {...props}
        >
            {children}
        </section>
    );
}
