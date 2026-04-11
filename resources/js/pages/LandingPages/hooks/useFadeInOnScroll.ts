import { useEffect, useRef, useState } from 'react';

import { useReducedMotion } from '@/hooks/use-reduced-motion';

/**
 * Lightweight scroll-triggered fade-in hook for landing page sections.
 *
 * Uses IntersectionObserver to detect when an element enters the viewport,
 * then sets `isVisible` to true (one-shot — once visible, stays visible).
 * Respects `prefers-reduced-motion` by skipping the animation entirely.
 *
 * @returns `ref` to attach to the target element and `isVisible` boolean
 */
export function useFadeInOnScroll(options?: { threshold?: number }) {
    const ref = useRef<HTMLElement>(null);
    const [isVisible, setIsVisible] = useState(false);
    const prefersReducedMotion = useReducedMotion();

    useEffect(() => {
        if (prefersReducedMotion) {
            setIsVisible(true);
            return;
        }

        const element = ref.current;
        if (!element) {
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setIsVisible(true);
                    observer.disconnect();
                }
            },
            { threshold: options?.threshold ?? 0.1 },
        );

        observer.observe(element);
        return () => observer.disconnect();
    }, [prefersReducedMotion, options?.threshold]);

    return { ref, isVisible };
}
