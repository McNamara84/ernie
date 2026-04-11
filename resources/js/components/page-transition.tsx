import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { type PropsWithChildren } from 'react';

import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { fadeTransition, fadeVariants } from '@/lib/animations';

/**
 * Wraps page content with a fade transition on Inertia navigation.
 * Uses the current page URL as a key to trigger AnimatePresence transitions.
 * Respects `prefers-reduced-motion` — renders without animation when enabled.
 */
export function PageTransition({ children }: PropsWithChildren) {
    const { url } = usePage();
    const prefersReducedMotion = useReducedMotion();

    if (prefersReducedMotion) {
        return <>{children}</>;
    }

    return (
        <AnimatePresence mode="wait" initial={false}>
            <motion.div
                key={url}
                data-slot="page-transition"
                className="flex min-h-0 flex-1 flex-col"
                variants={fadeVariants}
                initial="initial"
                animate="animate"
                exit="exit"
                transition={fadeTransition}
            >
                {children}
            </motion.div>
        </AnimatePresence>
    );
}
