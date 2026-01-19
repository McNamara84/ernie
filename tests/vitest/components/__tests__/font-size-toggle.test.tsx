import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import FontSizeToggle from '@/components/font-size-toggle';

// Mock the hook
const mockUpdateFontSize = vi.fn();
vi.mock('@/hooks/use-font-size', () => ({
    useFontSize: () => ({
        fontSize: 'regular',
        updateFontSize: mockUpdateFontSize,
    }),
}));

describe('FontSizeToggle', () => {
    beforeEach(() => {
        mockUpdateFontSize.mockClear();
    });

    it('renders the toggle group', () => {
        render(<FontSizeToggle />);

        expect(screen.getByRole('group', { name: /font size options/i })).toBeInTheDocument();
    });

    it('renders regular and large options', () => {
        render(<FontSizeToggle />);

        expect(screen.getByText('Regular')).toBeInTheDocument();
        expect(screen.getByText('Large')).toBeInTheDocument();
    });

    it('shows regular as active by default', () => {
        render(<FontSizeToggle />);

        const regularButton = screen.getByRole('button', { name: /regular/i });
        expect(regularButton).toHaveClass('bg-white');
    });

    it('calls updateFontSize when clicking on large', () => {
        render(<FontSizeToggle />);

        const largeButton = screen.getByRole('button', { name: /large/i });
        fireEvent.click(largeButton);

        expect(mockUpdateFontSize).toHaveBeenCalledWith('large');
    });

    it('calls updateFontSize when clicking on regular', () => {
        render(<FontSizeToggle />);

        const regularButton = screen.getByRole('button', { name: /set font size to regular/i });
        fireEvent.click(regularButton);

        expect(mockUpdateFontSize).toHaveBeenCalledWith('regular');
    });

    it('applies custom className', () => {
        render(<FontSizeToggle className="custom-class" data-testid="toggle" />);

        expect(screen.getByTestId('toggle')).toHaveClass('custom-class');
    });

    it('has accessible aria-labels on buttons', () => {
        render(<FontSizeToggle />);

        expect(
            screen.getByRole('button', { name: /set font size to regular: standard font size/i })
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /set font size to large: increased font size/i })
        ).toBeInTheDocument();
    });
});
