import { ArrowUp } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

const SCROLL_THRESHOLD = 300;

/**
 * Floating back-to-top button that appears after scrolling down.
 *
 * - Appears with fade + slide-up animation after 300px of scroll
 * - Respects `prefers-reduced-motion` (`auto` scroll, no animation)
 * - Minimum 44×44px touch target (WCAG 2.5.5)
 * - Uses `pointer-events-none` when hidden to prevent invisible click captures
 * - Syncs initial visibility on mount (handles scroll restoration / anchor nav)
 * - Guards state updates to avoid unnecessary re-renders during continuous scrolling
 */
export function BackToTopButton() {
    const [isVisible, setIsVisible] = useState(false);
    const wasVisibleRef = useRef(false);
    const prefersReducedMotion = useReducedMotion();

    const handleScroll = useCallback(() => {
        const shouldBeVisible = window.scrollY > SCROLL_THRESHOLD;
        if (shouldBeVisible !== wasVisibleRef.current) {
            wasVisibleRef.current = shouldBeVisible;
            setIsVisible(shouldBeVisible);
        }
    }, []);

    useEffect(() => {
        window.addEventListener('scroll', handleScroll, { passive: true });
        handleScroll();
        return () => window.removeEventListener('scroll', handleScroll);
    }, [handleScroll]);

    const scrollToTop = () => {
        window.scrollTo({
            top: 0,
            behavior: prefersReducedMotion ? 'auto' : 'smooth',
        });
    };

    return (
        <Button
            variant="outline"
            size="icon"
            onClick={scrollToTop}
            aria-label="Scroll to top"
            aria-hidden={!isVisible}
            tabIndex={isVisible ? 0 : -1}
            data-testid="back-to-top-button"
            className={`back-to-top-button fixed right-4 bottom-4 z-40 min-h-11 min-w-11 rounded-full border-gray-300 bg-white/90 shadow-lg backdrop-blur-sm hover:bg-gray-100 md:right-6 md:bottom-6 dark:border-gray-600 dark:bg-gray-800/90 dark:hover:bg-gray-700 ${
                isVisible ? 'pointer-events-auto' : 'pointer-events-none'
            } ${
                prefersReducedMotion
                    ? isVisible
                        ? 'opacity-100'
                        : 'opacity-0'
                    : `transition-all duration-200 ease-out ${isVisible ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0'}`
            }`}
        >
            <ArrowUp className="h-5 w-5 text-gfz-primary dark:text-gray-300" aria-hidden="true" />
        </Button>
    );
}
