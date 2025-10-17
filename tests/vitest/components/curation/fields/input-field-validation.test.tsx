import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { InputField } from '@/components/curation/fields/input-field';
import type { ValidationMessage } from '@/hooks/use-form-validation';

describe('InputField with Validation', () => {
    const mockValidationMessages: ValidationMessage[] = [
        { severity: 'error', message: 'This field is required' },
    ];

    describe('Basic rendering', () => {
        it('should render input field without validation', () => {
            render(<InputField id="test-input" label="Test Label" />);

            expect(screen.getByLabelText('Test Label')).toBeInTheDocument();
            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        });

        it('should render required indicator', () => {
            render(<InputField id="test-input" label="Test Label" required />);

            const label = screen.getByText('Test Label').closest('label');
            expect(label).toHaveTextContent('*');
        });

        it('should render help text when provided', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    helpText="This is helpful information"
                />,
            );

            expect(screen.getByText('This is helpful information')).toBeInTheDocument();
        });
    });

    describe('Validation messages', () => {
        it('should not show validation messages when not touched', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={mockValidationMessages}
                    touched={false}
                />,
            );

            expect(screen.queryByText('This field is required')).not.toBeInTheDocument();
        });

        it('should show validation messages when touched', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={mockValidationMessages}
                    touched={true}
                />,
            );

            expect(screen.getByText('This field is required')).toBeInTheDocument();
        });

        it('should show success message when field is valid', () => {
            const successMessages: ValidationMessage[] = [
                { severity: 'success', message: 'Looks good!' },
            ];

            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={successMessages}
                    touched={true}
                />,
            );

            expect(screen.getByText('Looks good!')).toBeInTheDocument();
        });

        it('should hide success messages when showSuccessFeedback is false', () => {
            const successMessages: ValidationMessage[] = [
                { severity: 'success', message: 'Looks good!' },
            ];

            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={successMessages}
                    touched={true}
                    showSuccessFeedback={false}
                />,
            );

            expect(screen.queryByText('Looks good!')).not.toBeInTheDocument();
        });

        it('should show multiple validation messages', () => {
            const messages: ValidationMessage[] = [
                { severity: 'error', message: 'Error message' },
                { severity: 'warning', message: 'Warning message' },
                { severity: 'info', message: 'Info message' },
            ];

            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={messages}
                    touched={true}
                />,
            );

            expect(screen.getByText('Error message')).toBeInTheDocument();
            expect(screen.getByText('Warning message')).toBeInTheDocument();
            expect(screen.getByText('Info message')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have aria-invalid when field has error and is touched', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={mockValidationMessages}
                    touched={true}
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).toHaveAttribute('aria-invalid', 'true');
        });

        it('should not have aria-invalid when field is not touched', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={mockValidationMessages}
                    touched={false}
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).not.toHaveAttribute('aria-invalid', 'true');
        });

        it('should link help text via aria-describedby', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    helpText="Help text"
                />,
            );

            const input = screen.getByLabelText('Test Label');
            const describedBy = input.getAttribute('aria-describedby');
            expect(describedBy).toContain('test-input-help');
        });

        it('should link validation feedback via aria-describedby when touched', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    validationMessages={mockValidationMessages}
                    touched={true}
                />,
            );

            const input = screen.getByLabelText('Test Label');
            const describedBy = input.getAttribute('aria-describedby');
            expect(describedBy).toContain('test-input-feedback');
        });

        it('should use aria-labelledby for visible label', () => {
            render(<InputField id="test-input" label="Test Label" />);

            const input = screen.getByLabelText('Test Label');
            expect(input).toHaveAttribute('aria-labelledby', 'test-input-label');
            expect(input).not.toHaveAttribute('aria-label');
        });

        it('should use aria-label for hidden label', () => {
            render(<InputField id="test-input" label="Test Label" hideLabel />);

            const input = screen.getByLabelText('Test Label');
            expect(input).toHaveAttribute('aria-label', 'Test Label');
            expect(input).not.toHaveAttribute('aria-labelledby');
        });
    });

    describe('Event handlers', () => {
        it('should call onValidationBlur when field is blurred', async () => {
            const user = userEvent.setup();
            const onValidationBlur = vi.fn();

            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    onValidationBlur={onValidationBlur}
                />,
            );

            const input = screen.getByLabelText('Test Label');
            await user.click(input);
            await user.tab(); // Blur the input

            expect(onValidationBlur).toHaveBeenCalledTimes(1);
        });

        it('should call original onBlur handler if provided', async () => {
            const user = userEvent.setup();
            const onBlur = vi.fn();
            const onValidationBlur = vi.fn();

            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    onBlur={onBlur}
                    onValidationBlur={onValidationBlur}
                />,
            );

            const input = screen.getByLabelText('Test Label');
            await user.click(input);
            await user.tab();

            expect(onBlur).toHaveBeenCalledTimes(1);
            expect(onValidationBlur).toHaveBeenCalledTimes(1);
        });

        it('should handle onChange normally', async () => {
            const user = userEvent.setup();
            const onChange = vi.fn();

            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    onChange={onChange}
                />,
            );

            const input = screen.getByLabelText('Test Label');
            await user.type(input, 'test');

            expect(onChange).toHaveBeenCalled();
        });
    });

    describe('Styling', () => {
        it('should apply custom className to container', () => {
            const { container } = render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    className="custom-class"
                />,
            );

            const wrapper = container.firstChild as HTMLElement;
            expect(wrapper).toHaveClass('custom-class');
        });

        it('should apply inputClassName to input element', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    inputClassName="custom-input-class"
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).toHaveClass('custom-input-class');
        });

        it('should merge containerProps className', () => {
            const { container } = render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    containerProps={{ className: 'container-class' }}
                />,
            );

            const wrapper = container.firstChild as HTMLElement;
            expect(wrapper).toHaveClass('container-class');
        });
    });

    describe('Input types', () => {
        it('should support number input type', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    type="number"
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).toHaveAttribute('type', 'number');
        });

        it('should support email input type', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    type="email"
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).toHaveAttribute('type', 'email');
        });

        it('should support password input type', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    type="password"
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).toHaveAttribute('type', 'password');
        });
    });

    describe('Required field', () => {
        it('should have required attribute when required prop is true', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    required
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).toBeRequired();
        });

        it('should not have required attribute when required prop is false', () => {
            render(
                <InputField
                    id="test-input"
                    label="Test Label"
                    required={false}
                />,
            );

            const input = screen.getByLabelText('Test Label');
            expect(input).not.toBeRequired();
        });
    });
});
