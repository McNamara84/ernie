import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { type PropsWithChildren } from 'react';

import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { fadeTransition, fadeVariants } from '@/lib/animations';

/**
 * Wraps page content with a fade transition on Inertia navigation.
 * Uses the current page URL as a key to trigger AnimatePresence transitions,
 * except within the editor, where saving a new draft updates the URL in place.
 * Respects `prefers-reduced-motion` — renders without animation when enabled.
 */
export function PageTransition({ children }: PropsWithChildren) {
    const { url } = usePage();
    const prefersReducedMotion = useReducedMotion();
    // Updating a new draft's editor URL must not unmount the form mid-edit.
    const transitionKey = url === '/editor' || url.startsWith('/editor?') ? '/editor' : url;

    if (prefersReducedMotion) {
        return (
            <div data-slot="page-transition" className="flex min-h-0 flex-1 flex-col">
                {children}
            </div>
        );
    }

    return (
        <AnimatePresence mode="wait" initial={false}>
            <motion.div
                key={transitionKey}
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
