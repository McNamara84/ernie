import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { type PropsWithChildren } from 'react';

import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { fadeTransition, fadeVariants } from '@/lib/animations';

interface DraftSaveTransition {
    fromUrl: string;
    resourceId: string;
}

export function pageTransitionKey(url: string, draftSaveTransition?: DraftSaveTransition): string {
    if (!draftSaveTransition || !url.startsWith('/editor?')) {
        return url;
    }

    const resourceId = new URLSearchParams(url.split('?')[1]).get('resourceId');
    return resourceId === draftSaveTransition.resourceId ? draftSaveTransition.fromUrl : url;
}

/**
 * Wraps page content with a fade transition on Inertia navigation.
 * Uses the current page URL as a key to trigger AnimatePresence transitions.
 * A newly created draft keeps its original editor key when its URL is assigned.
 * Respects `prefers-reduced-motion` — renders without animation when enabled.
 */
export function PageTransition({ children }: PropsWithChildren) {
    const { url, props } = usePage<{ draftSaveTransition?: DraftSaveTransition }>();
    const prefersReducedMotion = useReducedMotion();
    const transitionKey = pageTransitionKey(url, props.draftSaveTransition);

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
