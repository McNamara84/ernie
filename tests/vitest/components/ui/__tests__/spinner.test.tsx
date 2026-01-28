import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Spinner } from '@/components/ui/spinner';

describe('Spinner', () => {
    it('renders with default size (md)', () => {
        render(<Spinner data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toBeInTheDocument();
        expect(spinner).toHaveClass('animate-spin');
        expect(spinner).toHaveClass('h-5', 'w-5');
    });

    it('renders with xs size', () => {
        render(<Spinner size="xs" data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveClass('h-3', 'w-3');
    });

    it('renders with sm size', () => {
        render(<Spinner size="sm" data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveClass('h-4', 'w-4');
    });

    it('renders with lg size', () => {
        render(<Spinner size="lg" data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveClass('h-6', 'w-6');
    });

    it('renders with xl size', () => {
        render(<Spinner size="xl" data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveClass('h-8', 'w-8');
    });

    it('applies custom className', () => {
        render(<Spinner className="text-primary" data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveClass('text-primary');
        expect(spinner).toHaveClass('animate-spin');
    });

    it('has aria-hidden attribute for accessibility', () => {
        render(<Spinner data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveAttribute('aria-hidden', 'true');
    });

    it('forwards additional props', () => {
        render(<Spinner data-testid="spinner" aria-label="Loading content" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveAttribute('aria-label', 'Loading content');
    });

    it('combines size class with custom className', () => {
        render(<Spinner size="sm" className="mr-2 text-muted-foreground" data-testid="spinner" />);
        const spinner = screen.getByTestId('spinner');
        expect(spinner).toHaveClass('h-4', 'w-4', 'mr-2', 'text-muted-foreground', 'animate-spin');
    });
});
