import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { LoadingButton } from '@/components/ui/loading-button';

describe('LoadingButton', () => {
    it('renders children text', () => {
        render(<LoadingButton>Save</LoadingButton>);
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
    });

    it('is enabled by default', () => {
        render(<LoadingButton>Save</LoadingButton>);
        expect(screen.getByRole('button')).toBeEnabled();
    });

    it('disables the button when loading is true', () => {
        render(<LoadingButton loading>Save</LoadingButton>);
        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('shows a spinner when loading is true', () => {
        const { container } = render(<LoadingButton loading>Save</LoadingButton>);
        expect(container.querySelector('[data-slot="spinner"]')).toBeInTheDocument();
    });

    it('does not show a spinner when loading is false', () => {
        const { container } = render(<LoadingButton>Save</LoadingButton>);
        expect(container.querySelector('[data-slot="spinner"]')).not.toBeInTheDocument();
    });

    it('disables when disabled prop is set regardless of loading', () => {
        render(<LoadingButton disabled>Save</LoadingButton>);
        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('passes variant to the underlying Button', () => {
        const { container } = render(
            <LoadingButton variant="destructive">Delete</LoadingButton>,
        );
        const button = container.querySelector('[data-slot="loading-button"]');
        expect(button).toHaveAttribute('data-variant', 'destructive');
    });

    it('passes size to the underlying Button', () => {
        const { container } = render(
            <LoadingButton size="lg">Save</LoadingButton>,
        );
        const button = container.querySelector('[data-slot="loading-button"]');
        expect(button).toHaveAttribute('data-size', 'lg');
    });

    it('renders children alongside spinner when loading', () => {
        render(<LoadingButton loading>Saving...</LoadingButton>);
        expect(screen.getByText('Saving...')).toBeInTheDocument();
    });

    it('sets aria-busy when loading', () => {
        render(<LoadingButton loading>Save</LoadingButton>);
        expect(screen.getByRole('button')).toHaveAttribute('aria-busy', 'true');
    });

    it('does not set aria-busy when not loading', () => {
        render(<LoadingButton>Save</LoadingButton>);
        expect(screen.getByRole('button')).toHaveAttribute('aria-busy', 'false');
    });

    it('sets aria-disabled when loading', () => {
        render(<LoadingButton loading>Save</LoadingButton>);
        expect(screen.getByRole('button')).toHaveAttribute('aria-disabled', 'true');
    });

    it('does not set aria-disabled when idle', () => {
        render(<LoadingButton>Save</LoadingButton>);
        expect(screen.getByRole('button')).not.toHaveAttribute('aria-disabled');
    });
});
