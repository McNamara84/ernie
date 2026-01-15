import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { Switch } from '@/components/ui/switch';

describe('Switch', () => {
    it('renders the switch', () => {
        render(<Switch aria-label="Test switch" />);
        const switchElement = screen.getByRole('switch');
        expect(switchElement).toBeInTheDocument();
    });

    it('applies custom className', () => {
        render(<Switch className="custom-class" aria-label="Test switch" />);
        const switchElement = screen.getByRole('switch');
        expect(switchElement).toHaveClass('custom-class');
    });

    it('is unchecked by default', () => {
        render(<Switch aria-label="Test switch" />);
        const switchElement = screen.getByRole('switch');
        expect(switchElement).toHaveAttribute('data-state', 'unchecked');
    });

    it('can be checked', () => {
        render(<Switch defaultChecked aria-label="Test switch" />);
        const switchElement = screen.getByRole('switch');
        expect(switchElement).toHaveAttribute('data-state', 'checked');
    });

    it('calls onChange when clicked', () => {
        const onCheckedChange = vi.fn();
        render(<Switch onCheckedChange={onCheckedChange} aria-label="Test switch" />);
        const switchElement = screen.getByRole('switch');
        fireEvent.click(switchElement);
        expect(onCheckedChange).toHaveBeenCalledWith(true);
    });

    it('can be disabled', () => {
        render(<Switch disabled aria-label="Test switch" />);
        const switchElement = screen.getByRole('switch');
        expect(switchElement).toBeDisabled();
    });
});
