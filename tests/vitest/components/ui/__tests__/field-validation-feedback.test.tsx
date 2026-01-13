/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    CompactFieldValidationFeedback,
    FieldValidationFeedback,
    filterMessagesBySeverity,
    getFirstMessageBySeverity,
    hasMessageWithSeverity,
} from '@/components/ui/field-validation-feedback';
import type { ValidationMessage } from '@/hooks/use-form-validation';

describe('FieldValidationFeedback', () => {
    describe('rendering', () => {
        it('renders a single error message', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'error', message: 'This field is required' }]}
                />
            );
            
            expect(screen.getByText('This field is required')).toBeInTheDocument();
        });

        it('renders multiple messages', () => {
            render(
                <FieldValidationFeedback
                    messages={[
                        { severity: 'error', message: 'Error message' },
                        { severity: 'warning', message: 'Warning message' },
                    ]}
                />
            );
            
            expect(screen.getByText('Error message')).toBeInTheDocument();
            expect(screen.getByText('Warning message')).toBeInTheDocument();
        });

        it('returns null when messages array is empty', () => {
            const { container } = render(
                <FieldValidationFeedback messages={[]} />
            );
            
            expect(container).toBeEmptyDOMElement();
        });

        it('applies custom className', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'info', message: 'Info' }]}
                    className="my-custom-class"
                />
            );
            
            const container = screen.getByRole('alert');
            expect(container).toHaveClass('my-custom-class');
        });

        it('applies custom id', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'info', message: 'Info' }]}
                    id="custom-id"
                />
            );
            
            expect(document.getElementById('custom-id')).toBeInTheDocument();
        });
    });

    describe('message sorting', () => {
        it('sorts messages by severity priority (error first)', () => {
            render(
                <FieldValidationFeedback
                    messages={[
                        { severity: 'success', message: 'Success' },
                        { severity: 'error', message: 'Error' },
                        { severity: 'info', message: 'Info' },
                        { severity: 'warning', message: 'Warning' },
                    ]}
                />
            );
            
            const messages = screen.getAllByTestId(/validation-message-/);
            expect(messages[0]).toHaveAttribute('data-severity', 'error');
            expect(messages[1]).toHaveAttribute('data-severity', 'warning');
            expect(messages[2]).toHaveAttribute('data-severity', 'info');
            expect(messages[3]).toHaveAttribute('data-severity', 'success');
        });
    });

    describe('showSuccess prop', () => {
        it('shows success messages by default', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'success', message: 'Valid!' }]}
                />
            );
            
            expect(screen.getByText('Valid!')).toBeInTheDocument();
        });

        it('hides success messages when showSuccess is false', () => {
            const { container } = render(
                <FieldValidationFeedback
                    messages={[{ severity: 'success', message: 'Valid!' }]}
                    showSuccess={false}
                />
            );
            
            // All messages filtered, returns null
            expect(container).toBeEmptyDOMElement();
        });

        it('shows non-success messages when showSuccess is false', () => {
            render(
                <FieldValidationFeedback
                    messages={[
                        { severity: 'success', message: 'Success' },
                        { severity: 'error', message: 'Error' },
                    ]}
                    showSuccess={false}
                />
            );
            
            expect(screen.queryByText('Success')).not.toBeInTheDocument();
            expect(screen.getByText('Error')).toBeInTheDocument();
        });
    });

    describe('compact mode', () => {
        it('applies compact styling when compact is true', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'error', message: 'Error' }]}
                    compact
                />
            );
            
            const message = screen.getByTestId('validation-message-error');
            expect(message).toHaveClass('text-xs');
        });

        it('applies default styling when compact is false', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'error', message: 'Error' }]}
                />
            );
            
            const message = screen.getByTestId('validation-message-error');
            expect(message).toHaveClass('text-sm');
        });
    });

    describe('severity styling', () => {
        it('applies error styling', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'error', message: 'Error' }]}
                />
            );
            
            const message = screen.getByTestId('validation-message-error');
            expect(message).toHaveClass('text-destructive');
        });

        it('applies warning styling', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'warning', message: 'Warning' }]}
                />
            );
            
            const message = screen.getByTestId('validation-message-warning');
            expect(message).toHaveClass('text-amber-600');
        });

        it('applies success styling', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'success', message: 'Success' }]}
                />
            );
            
            const message = screen.getByTestId('validation-message-success');
            expect(message).toHaveClass('text-green-600');
        });

        it('applies info styling', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'info', message: 'Info' }]}
                />
            );
            
            const message = screen.getByTestId('validation-message-info');
            expect(message).toHaveClass('text-blue-600');
        });
    });

    describe('accessibility', () => {
        it('has alert role', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'error', message: 'Error' }]}
                />
            );
            
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('has polite aria-live', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'error', message: 'Error' }]}
                />
            );
            
            expect(screen.getByRole('alert')).toHaveAttribute('aria-live', 'polite');
        });

        it('has aria-atomic true', () => {
            render(
                <FieldValidationFeedback
                    messages={[{ severity: 'error', message: 'Error' }]}
                />
            );
            
            expect(screen.getByRole('alert')).toHaveAttribute('aria-atomic', 'true');
        });
    });
});

describe('CompactFieldValidationFeedback', () => {
    it('renders compact version', () => {
        render(
            <CompactFieldValidationFeedback
                messages={[{ severity: 'error', message: 'Error' }]}
            />
        );
        
        const message = screen.getByTestId('validation-message-error');
        expect(message).toHaveClass('text-xs');
    });
});

describe('helper functions', () => {
    const messages: ValidationMessage[] = [
        { severity: 'error', message: 'First error' },
        { severity: 'error', message: 'Second error' },
        { severity: 'warning', message: 'Warning' },
        { severity: 'info', message: 'Info' },
    ];

    describe('getFirstMessageBySeverity', () => {
        it('returns first message of specified severity', () => {
            const result = getFirstMessageBySeverity(messages, 'error');
            
            expect(result).toEqual({ severity: 'error', message: 'First error' });
        });

        it('returns undefined when no message matches', () => {
            const result = getFirstMessageBySeverity(messages, 'success');
            
            expect(result).toBeUndefined();
        });
    });

    describe('hasMessageWithSeverity', () => {
        it('returns true when severity exists', () => {
            expect(hasMessageWithSeverity(messages, 'error')).toBe(true);
            expect(hasMessageWithSeverity(messages, 'warning')).toBe(true);
        });

        it('returns false when severity does not exist', () => {
            expect(hasMessageWithSeverity(messages, 'success')).toBe(false);
        });
    });

    describe('filterMessagesBySeverity', () => {
        it('filters messages by single severity', () => {
            const result = filterMessagesBySeverity(messages, ['error']);
            
            expect(result).toHaveLength(2);
            expect(result.every(m => m.severity === 'error')).toBe(true);
        });

        it('filters messages by multiple severities', () => {
            const result = filterMessagesBySeverity(messages, ['error', 'warning']);
            
            expect(result).toHaveLength(3);
        });

        it('returns empty array when no matches', () => {
            const result = filterMessagesBySeverity(messages, ['success']);
            
            expect(result).toHaveLength(0);
        });
    });
});
