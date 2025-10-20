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
    const mockMessages: ValidationMessage[] = [
        { severity: 'error', message: 'This field is required' },
        { severity: 'warning', message: 'Value might be too short' },
        { severity: 'success', message: 'Looks good!' },
        { severity: 'info', message: 'Consider adding more detail' },
    ];

    describe('Rendering', () => {
        it('should render all messages by default', () => {
            render(<FieldValidationFeedback messages={mockMessages} />);

            expect(screen.getByText('This field is required')).toBeInTheDocument();
            expect(screen.getByText('Value might be too short')).toBeInTheDocument();
            expect(screen.getByText('Looks good!')).toBeInTheDocument();
            expect(screen.getByText('Consider adding more detail')).toBeInTheDocument();
        });

        it('should not render success messages when showSuccess is false', () => {
            render(<FieldValidationFeedback messages={mockMessages} showSuccess={false} />);

            expect(screen.getByText('This field is required')).toBeInTheDocument();
            expect(screen.getByText('Value might be too short')).toBeInTheDocument();
            expect(screen.queryByText('Looks good!')).not.toBeInTheDocument();
            expect(screen.getByText('Consider adding more detail')).toBeInTheDocument();
        });

        it('should render nothing when messages array is empty', () => {
            const { container } = render(<FieldValidationFeedback messages={[]} />);
            expect(container.firstChild).toBeNull();
        });

        it('should render with custom className', () => {
            const { container } = render(
                <FieldValidationFeedback
                    messages={[mockMessages[0]]}
                    className="custom-class"
                />,
            );

            expect(container.querySelector('.custom-class')).toBeInTheDocument();
        });
    });

    describe('Message sorting', () => {
        it('should display errors first, then warnings, info, and success', () => {
            const { container } = render(<FieldValidationFeedback messages={mockMessages} />);

            const messageElements = container.querySelectorAll('[data-severity]');
            expect(messageElements[0]).toHaveAttribute('data-severity', 'error');
            expect(messageElements[1]).toHaveAttribute('data-severity', 'warning');
            expect(messageElements[2]).toHaveAttribute('data-severity', 'info');
            expect(messageElements[3]).toHaveAttribute('data-severity', 'success');
        });
    });

    describe('Accessibility', () => {
        it('should have role="alert" and aria-live="polite"', () => {
            const { container } = render(
                <FieldValidationFeedback messages={[mockMessages[0]]} />,
            );

            const alertElement = container.querySelector('[role="alert"]');
            expect(alertElement).toBeInTheDocument();
            expect(alertElement).toHaveAttribute('aria-live', 'polite');
            expect(alertElement).toHaveAttribute('aria-atomic', 'true');
        });

        it('should have aria-label on icons', () => {
            render(<FieldValidationFeedback messages={mockMessages} />);

            // Verify that icons have aria-labels
            const icons = screen.getAllByLabelText(/Error|Warning|Success|Information/);
            expect(icons.length).toBeGreaterThan(0);
        });
    });

    describe('Compact variant', () => {
        it('should render in compact mode', () => {
            render(<CompactFieldValidationFeedback messages={[mockMessages[0]]} />);

            const message = screen.getByText('This field is required');
            expect(message).toBeInTheDocument();
        });
    });

    describe('Data attributes', () => {
        it('should have data-testid for each severity level', () => {
            render(<FieldValidationFeedback messages={mockMessages} />);

            expect(screen.getByTestId('validation-message-error')).toBeInTheDocument();
            expect(screen.getByTestId('validation-message-warning')).toBeInTheDocument();
            expect(screen.getByTestId('validation-message-success')).toBeInTheDocument();
            expect(screen.getByTestId('validation-message-info')).toBeInTheDocument();
        });
    });
});

describe('Helper functions', () => {
    const mockMessages: ValidationMessage[] = [
        { severity: 'error', message: 'Error 1' },
        { severity: 'error', message: 'Error 2' },
        { severity: 'warning', message: 'Warning 1' },
        { severity: 'success', message: 'Success 1' },
    ];

    describe('getFirstMessageBySeverity', () => {
        it('should return first message of specified severity', () => {
            const errorMessage = getFirstMessageBySeverity(mockMessages, 'error');
            expect(errorMessage).toEqual({ severity: 'error', message: 'Error 1' });
        });

        it('should return undefined if no message with severity exists', () => {
            const infoMessage = getFirstMessageBySeverity(mockMessages, 'info');
            expect(infoMessage).toBeUndefined();
        });
    });

    describe('hasMessageWithSeverity', () => {
        it('should return true if message with severity exists', () => {
            expect(hasMessageWithSeverity(mockMessages, 'error')).toBe(true);
            expect(hasMessageWithSeverity(mockMessages, 'warning')).toBe(true);
        });

        it('should return false if no message with severity exists', () => {
            expect(hasMessageWithSeverity(mockMessages, 'info')).toBe(false);
        });
    });

    describe('filterMessagesBySeverity', () => {
        it('should filter messages by single severity', () => {
            const errors = filterMessagesBySeverity(mockMessages, ['error']);
            expect(errors).toHaveLength(2);
            expect(errors.every((msg) => msg.severity === 'error')).toBe(true);
        });

        it('should filter messages by multiple severities', () => {
            const filtered = filterMessagesBySeverity(mockMessages, ['error', 'warning']);
            expect(filtered).toHaveLength(3);
        });

        it('should return empty array if no matches', () => {
            const filtered = filterMessagesBySeverity(mockMessages, ['info']);
            expect(filtered).toHaveLength(0);
        });
    });
});
