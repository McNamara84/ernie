import { router } from '@inertiajs/react';
import NProgress from 'nprogress';
import { useEffect } from 'react';

import { useReducedMotion } from '@/hooks/use-reduced-motion';

/**
 * Integrates NProgress with Inertia's router events.
 * Shows a thin progress bar at the top of the viewport during page navigation.
 * Respects `prefers-reduced-motion` — skips animation when enabled.
 *
 * Must be called once in a root layout component.
 */
export function useNProgress(): void {
    const prefersReducedMotion = useReducedMotion();

    useEffect(() => {
        NProgress.configure({
            showSpinner: false,
            minimum: 0.1,
            speed: prefersReducedMotion ? 0 : 300,
            trickleSpeed: prefersReducedMotion ? 0 : 200,
        });

        const removeStart = router.on('start', () => {
            NProgress.start();
        });

        const removeFinish = router.on('finish', () => {
            NProgress.done();
        });

        return () => {
            removeStart();
            removeFinish();
            NProgress.remove();
        };
    }, [prefersReducedMotion]);
}
