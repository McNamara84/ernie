import type { Transition, Variants } from 'framer-motion';

/** Duration for fade page transitions in seconds */
export const FADE_DURATION = 0.2;

/** Fade in/out animation variants for page transitions */
export const fadeVariants: Variants = {
    initial: { opacity: 0 },
    animate: { opacity: 1 },
    exit: { opacity: 0 },
};

/** Standard ease-in-out transition for fades */
export const fadeTransition: Transition = {
    duration: FADE_DURATION,
    ease: 'easeInOut',
};

/** Stagger container for animating lists of children */
export const staggerContainer: Variants = {
    animate: {
        transition: { staggerChildren: 0.05 },
    },
};

/**
 * Returns animation props that respect the reduced motion preference.
 * When reduced motion is active, returns `false` for initial/animate/exit
 * which tells Framer Motion to skip animations entirely.
 */
export function getReducedMotionProps(prefersReducedMotion: boolean) {
    if (prefersReducedMotion) {
        return {
            initial: false as const,
            animate: { opacity: 1 },
            exit: { opacity: 1 },
            transition: { duration: 0 },
        };
    }

    return {
        initial: fadeVariants.initial,
        animate: fadeVariants.animate,
        exit: fadeVariants.exit,
        transition: fadeTransition,
    };
}
