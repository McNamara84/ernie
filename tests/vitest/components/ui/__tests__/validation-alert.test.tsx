/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ValidationAlert } from '@/components/ui/validation-alert';

describe('ValidationAlert', () => {
    describe('rendering', () => {
        it('renders with single message', () => {
            render(<ValidationAlert severity="error" messages={['Field is required']} />);
            
            expect(screen.getByText('Field is required')).toBeInTheDocument();
        });

        it('renders multiple messages as list', () => {
            render(
                <ValidationAlert
                    severity="error"
                    messages={['First error', 'Second error', 'Third error']}
                />
            );
            
            expect(screen.getByText('First error')).toBeInTheDocument();
            expect(screen.getByText('Second error')).toBeInTheDocument();
            expect(screen.getByText('Third error')).toBeInTheDocument();
        });

        it('renders title when provided', () => {
            render(
                <ValidationAlert
                    severity="error"
                    title="Validation Failed"
                    messages={['Field is required']}
                />
            );
            
            expect(screen.getByText('Validation Failed')).toBeInTheDocument();
        });

        it('returns null when messages array is empty', () => {
            const { container } = render(
                <ValidationAlert severity="error" messages={[]} />
            );
            
            expect(container).toBeEmptyDOMElement();
        });

        it('applies data-testid when provided', () => {
            render(
                <ValidationAlert
                    severity="error"
                    messages={['Error']}
                    data-testid="validation-error"
                />
            );
            
            expect(screen.getByTestId('validation-error')).toBeInTheDocument();
        });
    });

    describe('severity levels', () => {
        it('renders error severity with alert role', () => {
            render(<ValidationAlert severity="error" messages={['Error']} />);
            
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('renders warning severity with alert role', () => {
            render(<ValidationAlert severity="warning" messages={['Warning']} />);
            
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('renders info severity with status role', () => {
            render(<ValidationAlert severity="info" messages={['Info']} />);
            
            expect(screen.getByRole('status')).toBeInTheDocument();
        });

        it('applies error styling', () => {
            render(
                <ValidationAlert
                    severity="error"
                    messages={['Error']}
                    data-testid="alert"
                />
            );
            
            const alert = screen.getByTestId('alert');
            expect(alert).toHaveClass('text-destructive');
        });

        it('applies warning styling', () => {
            render(
                <ValidationAlert
                    severity="warning"
                    messages={['Warning']}
                    data-testid="alert"
                />
            );
            
            const alert = screen.getByTestId('alert');
            expect(alert).toHaveClass('text-amber-900');
        });

        it('applies info styling', () => {
            render(
                <ValidationAlert
                    severity="info"
                    messages={['Info']}
                    data-testid="alert"
                />
            );
            
            const alert = screen.getByTestId('alert');
            expect(alert).toHaveClass('text-blue-900');
        });
    });

    describe('accessibility', () => {
        it('uses polite aria-live by default', () => {
            render(
                <ValidationAlert
                    severity="error"
                    messages={['Error']}
                    data-testid="alert"
                />
            );
            
            expect(screen.getByTestId('alert')).toHaveAttribute('aria-live', 'polite');
        });

        it('uses assertive aria-live when assertive prop is true', () => {
            render(
                <ValidationAlert
                    severity="error"
                    messages={['Critical error']}
                    assertive
                    data-testid="alert"
                />
            );
            
            expect(screen.getByTestId('alert')).toHaveAttribute('aria-live', 'assertive');
        });

        it('is not focusable by default', () => {
            render(
                <ValidationAlert
                    severity="error"
                    messages={['Error']}
                    data-testid="alert"
                />
            );
            
            expect(screen.getByTestId('alert')).not.toHaveAttribute('tabIndex');
        });

        it('is focusable when focusable prop is true', () => {
            render(
                <ValidationAlert
                    severity="error"
                    messages={['Error']}
                    focusable
                    data-testid="alert"
                />
            );
            
            expect(screen.getByTestId('alert')).toHaveAttribute('tabIndex', '-1');
        });
    });

    describe('message display', () => {
        it('renders single message as paragraph', () => {
            render(<ValidationAlert severity="info" messages={['Single message']} />);
            
            const paragraph = screen.getByText('Single message');
            expect(paragraph.tagName).toBe('P');
        });

        it('renders multiple messages as list items', () => {
            render(
                <ValidationAlert
                    severity="info"
                    messages={['First', 'Second']}
                />
            );
            
            const list = screen.getByRole('list');
            expect(list).toBeInTheDocument();
            
            const items = screen.getAllByRole('listitem');
            expect(items).toHaveLength(2);
        });

        it('renders single message with title below title', () => {
            render(
                <ValidationAlert
                    severity="error"
                    title="Error Title"
                    messages={['Single message']}
                />
            );
            
            expect(screen.getByText('Error Title')).toBeInTheDocument();
            expect(screen.getByText('Single message')).toBeInTheDocument();
        });
    });

    describe('className', () => {
        it('applies custom className', () => {
            render(
                <ValidationAlert
                    severity="info"
                    messages={['Test']}
                    className="my-custom-class"
                    data-testid="alert"
                />
            );
            
            expect(screen.getByTestId('alert')).toHaveClass('my-custom-class');
        });
    });
});
