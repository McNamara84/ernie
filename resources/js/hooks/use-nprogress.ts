import { router } from '@inertiajs/react';
import NProgress from 'nprogress';
import { useEffect, useRef } from 'react';

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
    const motionRef = useRef(prefersReducedMotion);

    // Keep ref in sync without re-running the event-listener effect
    useEffect(() => {
        motionRef.current = prefersReducedMotion;
        NProgress.configure({
            showSpinner: false,
            minimum: 0.1,
            speed: prefersReducedMotion ? 0 : 300,
            trickleSpeed: prefersReducedMotion ? 0 : 200,
        });
    }, [prefersReducedMotion]);

    // Subscribe to router events once; stable across reduced-motion changes.
    // A 250 ms delay prevents the progress bar from flashing during Inertia
    // prefetch requests (triggered on hover) and fast cached navigations.
    useEffect(() => {
        NProgress.configure({
            showSpinner: false,
            minimum: 0.1,
            speed: motionRef.current ? 0 : 300,
            trickleSpeed: motionRef.current ? 0 : 200,
        });

        let timeout: ReturnType<typeof setTimeout> | null = null;

        const removeStart = router.on('start', () => {
            if (timeout) {
                clearTimeout(timeout);
            }
            timeout = setTimeout(() => {
                NProgress.start();
            }, 250);
        });

        const removeFinish = router.on('finish', () => {
            if (timeout) {
                clearTimeout(timeout);
                timeout = null;
            }
            NProgress.done();
        });

        return () => {
            if (timeout) {
                clearTimeout(timeout);
            }
            removeStart();
            removeFinish();
            NProgress.remove();
        };
    }, []);
}
