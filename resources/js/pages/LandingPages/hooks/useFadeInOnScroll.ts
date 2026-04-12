import { useCallback, useEffect, useRef, useState } from 'react';

import { useReducedMotion } from '@/hooks/use-reduced-motion';

/**
 * Module-level flag so the `js-fade-ready` class is added to `<html>` at most
 * once per page load.  The actual DOM write happens in a `useEffect` (after
 * commit) to avoid side effects during render — important for StrictMode.
 */
let jsFadeReadyApplied = false;

/** @internal Resets the module-level flag — only for use in tests. */
export function _resetJsFadeReady(): void {
    jsFadeReadyApplied = false;
}

/**
 * Lightweight scroll-triggered fade-in hook for landing page sections.
 *
 * Uses IntersectionObserver to detect when an element enters the viewport,
 * then adds the CSS class `is-visible` (one-shot — once visible, stays visible).
 * The actual animation is driven by the `fade-in-on-scroll` CSS class in app.css.
 *
 * Respects `prefers-reduced-motion` by adding `is-visible` immediately on mount
 * so no opacity-0 flash occurs.
 *
 * Falls back to immediately visible when IntersectionObserver is unavailable
 * (older browsers, some embedded WebViews).
 *
 * Uses a callback ref so the observer reattaches when the target element
 * changes (e.g. conditional rendering like SSR skeleton → mounted map).
 *
 * @param options.threshold Intersection ratio to trigger visibility (default 0.1).
 *   Must be stable for the lifetime of the component — changing it after mount
 *   has no effect because the observer is created once per element.
 * @returns Callback ref to attach to the target element
 */
export function useFadeInOnScroll(options?: { threshold?: number }): (element: HTMLElement | null) => void {
    const [node, setNode] = useState<HTMLElement | null>(null);
    const prefersReducedMotion = useReducedMotion();

    const observerSupported = typeof IntersectionObserver !== 'undefined';

    // Stable callback ref — consumers attach via `ref={ref}`
    const ref = useCallback(
        (element: HTMLElement | null) => {
            setNode(element);

            // Immediately make visible when reduced motion is active or IO unavailable
            if (element && (prefersReducedMotion || !observerSupported)) {
                element.classList.add('is-visible');
            }
        },
        [prefersReducedMotion, observerSupported],
    );

    // Capture threshold once — intentionally not reactive (see JSDoc above)
    const thresholdRef = useRef(options?.threshold ?? 0.1);

    // Add `js-fade-ready` to <html> once after commit so CSS fade-in rules
    // activate. Runs in useEffect (not during render) to satisfy StrictMode.
    useEffect(() => {
        if (!jsFadeReadyApplied) {
            document.documentElement.classList.add('js-fade-ready');
            jsFadeReadyApplied = true;
        }
    }, []);

    useEffect(() => {
        if (!node) {
            return;
        }

        if (prefersReducedMotion || !observerSupported) {
            node.classList.add('is-visible');
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.disconnect();
                }
            },
            { threshold: thresholdRef.current },
        );

        observer.observe(node);
        return () => observer.disconnect();
    }, [prefersReducedMotion, observerSupported, node]);

    return ref;
}
