import { useCallback, useEffect, useRef, useState } from 'react';

import { useReducedMotion } from '@/hooks/use-reduced-motion';

/**
 * Lightweight scroll-triggered fade-in hook for landing page sections.
 *
 * Uses IntersectionObserver to detect when an element enters the viewport,
 * then sets `isVisible` to true (one-shot — once visible, stays visible).
 * Respects `prefers-reduced-motion` by returning `isVisible=true` on the
 * very first render so no opacity-0 flash occurs.
 *
 * Falls back to immediately visible when IntersectionObserver is unavailable
 * (older browsers, some embedded WebViews).
 *
 * Uses a callback ref so the observer reattaches when the target element
 * changes (e.g. conditional rendering like SSR skeleton → mounted map).
 *
 * @returns `ref` callback ref to attach to the target element and `isVisible` boolean
 */
export function useFadeInOnScroll(options?: { threshold?: number }) {
    const [node, setNode] = useState<HTMLElement | null>(null);
    const prefersReducedMotion = useReducedMotion();

    const observerSupported = typeof IntersectionObserver !== 'undefined';

    // Initialize visible immediately when reduced motion is active or
    // IntersectionObserver is unavailable, so the first paint never renders opacity-0.
    const [isVisible, setIsVisible] = useState(prefersReducedMotion || !observerSupported);

    // Stable callback ref — consumers attach via `ref={ref}`
    const ref = useCallback((element: HTMLElement | null) => {
        setNode(element);
    }, []);

    // Store threshold in a ref to keep the effect dep list stable
    const thresholdRef = useRef(options?.threshold ?? 0.1);
    thresholdRef.current = options?.threshold ?? 0.1;

    useEffect(() => {
        if (prefersReducedMotion || !observerSupported) {
            setIsVisible(true);
            return;
        }

        if (!node) {
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setIsVisible(true);
                    observer.disconnect();
                }
            },
            { threshold: thresholdRef.current },
        );

        observer.observe(node);
        return () => observer.disconnect();
    }, [prefersReducedMotion, observerSupported, node]);

    return { ref, isVisible };
}
