import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { Toaster } from '@/components/ui/sonner';

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
});

describe('Toaster theme integration', () => {
    it('renders with theme from useAppearance hook', () => {
        // The Toaster uses useAppearance hook for theme - verify it renders
        expect(() => render(<Toaster />)).not.toThrow();
    });
});
