/**
 * @vitest-environment jsdom
 */
import { act } from '@testing-library/react';
import { cleanup, render, screen } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { BackToTopButton } from '@/pages/LandingPages/components/BackToTopButton';

// Mock useReducedMotion
const mockUseReducedMotion = vi.fn(() => false);
vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: () => mockUseReducedMotion(),
}));

describe('BackToTopButton', () => {
    beforeEach(() => {
        mockUseReducedMotion.mockReturnValue(false);
        Object.defineProperty(window, 'scrollY', { value: 0, writable: true, configurable: true });
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('renders with correct aria-label', () => {
        render(<BackToTopButton />);
        expect(screen.getByRole('button', { name: 'Scroll to top' })).toBeInTheDocument();
    });

    it('is hidden when scrollY is below threshold', () => {
        render(<BackToTopButton />);
        const button = screen.getByRole('button', { name: 'Scroll to top' });
        expect(button).toHaveClass('pointer-events-none');
        expect(button).toHaveClass('opacity-0');
    });

    it('becomes visible after scrolling past 300px', () => {
        render(<BackToTopButton />);

        window.scrollY = 350;
        act(() => {
            window.dispatchEvent(new Event('scroll'));
        });

        const button = screen.getByRole('button', { name: 'Scroll to top' });
        expect(button).toHaveClass('pointer-events-auto');
        expect(button).toHaveClass('opacity-100');
    });

    it('hides again when scrolling back up', () => {
        render(<BackToTopButton />);

        // Scroll down
        window.scrollY = 400;
        act(() => {
            window.dispatchEvent(new Event('scroll'));
        });

        // Scroll back up
        window.scrollY = 100;
        act(() => {
            window.dispatchEvent(new Event('scroll'));
        });

        const button = screen.getByRole('button', { name: 'Scroll to top' });
        expect(button).toHaveClass('pointer-events-none');
        expect(button).toHaveClass('opacity-0');
    });

    it('scrolls to top with smooth behavior when clicked', () => {
        const scrollToSpy = vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
        render(<BackToTopButton />);

        // Make visible
        window.scrollY = 500;
        act(() => {
            window.dispatchEvent(new Event('scroll'));
        });

        screen.getByRole('button', { name: 'Scroll to top' }).click();
        expect(scrollToSpy).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('uses auto scroll when prefers-reduced-motion is active', () => {
        mockUseReducedMotion.mockReturnValue(true);
        const scrollToSpy = vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
        render(<BackToTopButton />);

        // Make visible
        window.scrollY = 500;
        act(() => {
            window.dispatchEvent(new Event('scroll'));
        });

        screen.getByRole('button', { name: 'Scroll to top' }).click();
        expect(scrollToSpy).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });

    it('has minimum 44x44px touch target', () => {
        render(<BackToTopButton />);
        const button = screen.getByRole('button', { name: 'Scroll to top' });
        expect(button).toHaveClass('min-h-11');
        expect(button).toHaveClass('min-w-11');
    });

    it('has back-to-top-button class for print CSS targeting', () => {
        render(<BackToTopButton />);
        const button = screen.getByRole('button', { name: 'Scroll to top' });
        expect(button).toHaveClass('back-to-top-button');
    });

    it('is visible on mount when page is already scrolled', () => {
        window.scrollY = 500;
        render(<BackToTopButton />);
        const button = screen.getByRole('button', { name: 'Scroll to top' });
        expect(button).toHaveClass('pointer-events-auto');
        expect(button).toHaveClass('opacity-100');
    });

    it('removes scroll listener on unmount', () => {
        const removeSpy = vi.spyOn(window, 'removeEventListener');
        const { unmount } = render(<BackToTopButton />);
        unmount();
        expect(removeSpy).toHaveBeenCalledWith('scroll', expect.any(Function));
    });

    it('does not apply custom transition classes when reduced motion is preferred', () => {
        mockUseReducedMotion.mockReturnValue(true);
        render(<BackToTopButton />);
        const button = screen.getByRole('button', { name: 'Scroll to top' });
        expect(button.className).not.toContain('duration-200');
        expect(button.className).not.toContain('ease-out');
    });
});
