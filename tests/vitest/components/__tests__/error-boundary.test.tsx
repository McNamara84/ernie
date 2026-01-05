import { fireEvent, render, screen } from '@testing-library/react';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

import { ErrorBoundary, SectionErrorBoundary } from '@/components/error-boundary';

// Component that always throws an error
function ThrowError(): never {
    throw new Error('Test error message');
}

// Component that works normally
function WorkingComponent() {
    return <div>Working content</div>;
}

describe('ErrorBoundary', () => {
    // Suppress console.error during tests since we're intentionally throwing errors
    const originalError = console.error;
    beforeAll(() => {
        console.error = vi.fn();
    });
    afterAll(() => {
        console.error = originalError;
    });

    it('renders children when there is no error', () => {
        render(
            <ErrorBoundary>
                <WorkingComponent />
            </ErrorBoundary>
        );

        expect(screen.getByText('Working content')).toBeInTheDocument();
    });

    it('renders error UI when a child throws an error', () => {
        render(
            <ErrorBoundary>
                <ThrowError />
            </ErrorBoundary>
        );

        expect(screen.getByText('Something went wrong')).toBeInTheDocument();
        expect(screen.getByText(/An unexpected error occurred/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /reload page/i })).toBeInTheDocument();
    });

    it('renders custom fallback when provided', () => {
        render(
            <ErrorBoundary fallback={<div>Custom error message</div>}>
                <ThrowError />
            </ErrorBoundary>
        );

        expect(screen.getByText('Custom error message')).toBeInTheDocument();
        expect(screen.queryByText('Something went wrong')).not.toBeInTheDocument();
    });

    it('calls onError callback when error occurs', () => {
        const onError = vi.fn();

        render(
            <ErrorBoundary onError={onError}>
                <ThrowError />
            </ErrorBoundary>
        );

        expect(onError).toHaveBeenCalledTimes(1);
        expect(onError).toHaveBeenCalledWith(
            expect.objectContaining({ message: 'Test error message' }),
            expect.objectContaining({ componentStack: expect.any(String) })
        );
    });

    it('resets error state when "Try again" is clicked', () => {
        // We need a component that conditionally throws
        let shouldThrow = true;
        function ConditionalThrow() {
            if (shouldThrow) {
                throw new Error('Conditional error');
            }
            return <div>Recovered content</div>;
        }

        const { rerender } = render(
            <ErrorBoundary>
                <ConditionalThrow />
            </ErrorBoundary>
        );

        // Error state
        expect(screen.getByText('Something went wrong')).toBeInTheDocument();

        // Fix the component and click retry
        shouldThrow = false;
        fireEvent.click(screen.getByRole('button', { name: /try again/i }));

        // Need to rerender to trigger the component again
        rerender(
            <ErrorBoundary>
                <ConditionalThrow />
            </ErrorBoundary>
        );

        expect(screen.getByText('Recovered content')).toBeInTheDocument();
    });
});

describe('SectionErrorBoundary', () => {
    const originalError = console.error;
    beforeAll(() => {
        console.error = vi.fn();
    });
    afterAll(() => {
        console.error = originalError;
    });

    it('renders children when there is no error', () => {
        render(
            <SectionErrorBoundary>
                <WorkingComponent />
            </SectionErrorBoundary>
        );

        expect(screen.getByText('Working content')).toBeInTheDocument();
    });

    it('renders inline error UI when a child throws', () => {
        render(
            <SectionErrorBoundary sectionName="Authors">
                <ThrowError />
            </SectionErrorBoundary>
        );

        expect(screen.getByText(/Failed to load Authors/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
    });

    it('renders generic error message when sectionName is not provided', () => {
        render(
            <SectionErrorBoundary>
                <ThrowError />
            </SectionErrorBoundary>
        );

        expect(screen.getByText(/Failed to load this section/)).toBeInTheDocument();
    });
});
