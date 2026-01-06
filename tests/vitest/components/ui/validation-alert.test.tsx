import { render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { describe, expect, it } from 'vitest';

import { ValidationAlert } from '@/components/ui/validation-alert';

describe('ValidationAlert', () => {
    describe('Rendering', () => {
        it('should render single message without list', () => {
            render(<ValidationAlert severity="error" messages={['This field is required']} />);

            expect(screen.getByText('This field is required')).toBeInTheDocument();
            expect(screen.queryByRole('list')).not.toBeInTheDocument();
        });

        it('should render multiple messages as list', () => {
            render(<ValidationAlert severity="error" messages={['First error', 'Second error', 'Third error']} />);

            expect(screen.getByRole('list')).toBeInTheDocument();
            expect(screen.getByText('First error')).toBeInTheDocument();
            expect(screen.getByText('Second error')).toBeInTheDocument();
            expect(screen.getByText('Third error')).toBeInTheDocument();
        });

        it('should render title when provided', () => {
            render(<ValidationAlert severity="error" title="Validation Errors" messages={['Error message']} />);

            expect(screen.getByText('Validation Errors')).toBeInTheDocument();
        });

        it('should not render when messages array is empty', () => {
            const { container } = render(<ValidationAlert severity="error" messages={[]} />);

            expect(container.firstChild).toBeNull();
        });

        it('should render with data-testid', () => {
            render(<ValidationAlert severity="error" messages={['Error']} data-testid="validation-alert-test" />);

            expect(screen.getByTestId('validation-alert-test')).toBeInTheDocument();
        });
    });

    describe('Severity Levels', () => {
        it('should render error severity with correct styling', () => {
            const { container } = render(<ValidationAlert severity="error" messages={['Error message']} />);

            expect(container.firstChild).toHaveClass('border-destructive/50', 'bg-destructive/10', 'text-destructive');
        });

        it('should render warning severity with correct styling', () => {
            const { container } = render(<ValidationAlert severity="warning" messages={['Warning message']} />);

            expect(container.firstChild).toHaveClass('border-amber-300', 'bg-amber-50', 'text-amber-900');
        });

        it('should render info severity with correct styling', () => {
            const { container } = render(<ValidationAlert severity="info" messages={['Info message']} />);

            expect(container.firstChild).toHaveClass('border-blue-200', 'bg-blue-50', 'text-blue-900');
        });

        it('should use alert role for error severity', () => {
            render(<ValidationAlert severity="error" messages={['Error']} />);

            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('should use alert role for warning severity', () => {
            render(<ValidationAlert severity="warning" messages={['Warning']} />);

            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('should use status role for info severity', () => {
            render(<ValidationAlert severity="info" messages={['Info']} />);

            expect(screen.getByRole('status')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have aria-live polite by default', () => {
            render(<ValidationAlert severity="error" messages={['Error']} />);

            expect(screen.getByRole('alert')).toHaveAttribute('aria-live', 'polite');
        });

        it('should have aria-live assertive when assertive prop is true', () => {
            render(<ValidationAlert severity="error" messages={['Error']} assertive />);

            expect(screen.getByRole('alert')).toHaveAttribute('aria-live', 'assertive');
        });

        it('should have tabIndex -1 when focusable prop is true', () => {
            render(<ValidationAlert severity="error" messages={['Error']} focusable />);

            expect(screen.getByRole('alert')).toHaveAttribute('tabIndex', '-1');
        });

        it('should not have tabIndex when focusable prop is false', () => {
            render(<ValidationAlert severity="error" messages={['Error']} />);

            expect(screen.getByRole('alert')).not.toHaveAttribute('tabIndex');
        });

        it('should forward ref correctly', () => {
            const ref = createRef<HTMLDivElement>();
            render(<ValidationAlert ref={ref} severity="error" messages={['Error']} />);

            expect(ref.current).toBeInstanceOf(HTMLDivElement);
            expect(ref.current).toHaveRole('alert');
        });

        it('should have icon with aria-hidden', () => {
            const { container } = render(<ValidationAlert severity="error" messages={['Error']} />);

            const svg = container.querySelector('svg');
            expect(svg).toHaveAttribute('aria-hidden', 'true');
        });
    });

    describe('Styling', () => {
        it('should accept custom className', () => {
            const { container } = render(<ValidationAlert severity="error" messages={['Error']} className="custom-class" />);

            expect(container.firstChild).toHaveClass('custom-class');
        });

        it('should have margin-bottom by default', () => {
            const { container } = render(<ValidationAlert severity="error" messages={['Error']} />);

            expect(container.firstChild).toHaveClass('mb-4');
        });

        it('should have rounded border', () => {
            const { container } = render(<ValidationAlert severity="error" messages={['Error']} />);

            expect(container.firstChild).toHaveClass('rounded-md', 'border');
        });
    });

    describe('Title and Messages Combination', () => {
        it('should render title with single message on separate line', () => {
            render(<ValidationAlert severity="error" title="Error" messages={['Single message']} />);

            expect(screen.getByText('Error')).toBeInTheDocument();
            expect(screen.getByText('Single message')).toBeInTheDocument();
        });

        it('should render title with multiple messages as list', () => {
            render(<ValidationAlert severity="error" title="Errors Found" messages={['First', 'Second']} />);

            expect(screen.getByText('Errors Found')).toBeInTheDocument();
            expect(screen.getByRole('list')).toBeInTheDocument();
        });
    });
});
