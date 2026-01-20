import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { FontSizeQuickToggle } from '@/components/font-size-quick-toggle';

// Hoisted mocks
const mocks = vi.hoisted(() => ({
    fontSize: 'regular' as 'regular' | 'large',
    updateFontSize: vi.fn(),
}));

vi.mock('@/hooks/use-font-size', () => ({
    useFontSize: () => ({
        fontSize: mocks.fontSize,
        updateFontSize: mocks.updateFontSize,
    }),
}));

describe('FontSizeQuickToggle', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.fontSize = 'regular';
    });

    it('renders the toggle button', () => {
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        expect(button).toBeInTheDocument();
    });

    it('has correct aria-label for regular font size', () => {
        mocks.fontSize = 'regular';
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        expect(button).toHaveAttribute(
            'aria-label',
            'Font size: Regular. Click to switch to large font size.',
        );
    });

    it('has correct aria-label for large font size', () => {
        mocks.fontSize = 'large';
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        expect(button).toHaveAttribute(
            'aria-label',
            'Font size: Large. Click to switch to regular font size.',
        );
    });

    it('calls updateFontSize with "large" when clicking in regular mode', () => {
        mocks.fontSize = 'regular';
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        fireEvent.click(button);

        expect(mocks.updateFontSize).toHaveBeenCalledWith('large');
    });

    it('calls updateFontSize with "regular" when clicking in large mode', () => {
        mocks.fontSize = 'large';
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        fireEvent.click(button);

        expect(mocks.updateFontSize).toHaveBeenCalledWith('regular');
    });

    it('applies scale class to icon when font size is large', () => {
        mocks.fontSize = 'large';
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        const icon = button.querySelector('svg');
        expect(icon).toHaveClass('scale-110');
    });

    it('does not apply scale class to icon when font size is regular', () => {
        mocks.fontSize = 'regular';
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        const icon = button.querySelector('svg');
        expect(icon).not.toHaveClass('scale-110');
    });

    it('has ghost variant styling', () => {
        render(<FontSizeQuickToggle />);

        const button = screen.getByRole('button');
        // Ghost variant doesn't have bg-primary, check for proper button rendering
        expect(button).toBeInTheDocument();
        expect(button).toHaveClass('h-8', 'w-8');
    });
});
