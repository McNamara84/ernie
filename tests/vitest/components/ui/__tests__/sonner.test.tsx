import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { Toaster } from '@/components/ui/sonner';

vi.mock('sonner', () => ({
    Toaster: ({ icons }: { icons?: Record<string, ReactNode> }) => (
        <section aria-label="Toast notifications">{icons?.loading}</section>
    ),
}));

// Mock the useAppearance hook
vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({
        appearance: 'light',
        updateAppearance: vi.fn(),
    }),
}));

describe('Toaster', () => {
    it('renders without crashing', () => {
        // Toaster component should render without throwing
        expect(() => render(<Toaster />)).not.toThrow();
    });

    it('accepts position prop without errors', () => {
        expect(() => render(<Toaster position="top-center" />)).not.toThrow();
    });

    it('accepts richColors prop without errors', () => {
        expect(() => render(<Toaster richColors />)).not.toThrow();
    });

    it('accepts expand prop without errors', () => {
        expect(() => render(<Toaster expand />)).not.toThrow();
    });

    it('uses a decorative shared spinner for loading toasts', () => {
        render(<Toaster />);

        const toaster = screen.getByRole('region');
        const loadingIcon = toaster.querySelector('[data-slot="spinner"]');
        expect(loadingIcon).toHaveClass('h-4', 'w-4', 'animate-spin');
        expect(loadingIcon).toHaveAttribute('aria-hidden', 'true');
        expect(loadingIcon).not.toHaveAttribute('role');
        expect(loadingIcon).not.toHaveAttribute('aria-label');
    });
});

describe('Toaster theme integration', () => {
    it('renders with theme from useAppearance hook', () => {
        // The Toaster uses useAppearance hook for theme - verify it renders
        expect(() => render(<Toaster />)).not.toThrow();
    });
});
