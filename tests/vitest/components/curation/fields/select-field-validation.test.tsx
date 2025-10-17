import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { SelectField } from '@/components/curation/fields/select-field';
import type { ValidationMessage } from '@/hooks/use-form-validation';

describe('SelectField with Validation', () => {
    const mockOptions = [
        { value: 'option1', label: 'Option 1' },
        { value: 'option2', label: 'Option 2' },
        { value: 'option3', label: 'Option 3' },
    ];

    const mockValidationMessages: ValidationMessage[] = [
        { severity: 'error', message: 'Please select an option' },
    ];

    describe('Basic rendering', () => {
        it('should render select field without validation', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                />,
            );

            expect(screen.getByText('Test Label')).toBeInTheDocument();
        });

        it('should render required indicator', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    required
                />,
            );

            const label = screen.getByText('Test Label').closest('label');
            expect(label).toHaveTextContent('*');
        });

        it('should render help text when provided', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    helpText="Choose an option from the list"
                />,
            );

            expect(screen.getByText('Choose an option from the list')).toBeInTheDocument();
        });

        it('should display placeholder when no value is selected', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    placeholder="Choose one"
                />,
            );

            expect(screen.getByText('Choose one')).toBeInTheDocument();
        });
    });

    describe('Validation messages', () => {
        it('should not show validation messages when not touched', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={mockValidationMessages}
                    touched={false}
                />,
            );

            expect(screen.queryByText('Please select an option')).not.toBeInTheDocument();
        });

        it('should show validation messages when touched', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={mockValidationMessages}
                    touched={true}
                />,
            );

            expect(screen.getByText('Please select an option')).toBeInTheDocument();
        });

        it('should show success message when field is valid', () => {
            const successMessages: ValidationMessage[] = [
                { severity: 'success', message: 'Good choice!' },
            ];

            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value="option1"
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={successMessages}
                    touched={true}
                />,
            );

            expect(screen.getByText('Good choice!')).toBeInTheDocument();
        });

        it('should hide success messages when showSuccessFeedback is false', () => {
            const successMessages: ValidationMessage[] = [
                { severity: 'success', message: 'Good choice!' },
            ];

            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value="option1"
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={successMessages}
                    touched={true}
                    showSuccessFeedback={false}
                />,
            );

            expect(screen.queryByText('Good choice!')).not.toBeInTheDocument();
        });

        it('should show multiple validation messages', () => {
            const messages: ValidationMessage[] = [
                { severity: 'error', message: 'Error message' },
                { severity: 'info', message: 'Info message' },
            ];

            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={messages}
                    touched={true}
                />,
            );

            expect(screen.getByText('Error message')).toBeInTheDocument();
            expect(screen.getByText('Info message')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have aria-invalid when field has error and is touched', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={mockValidationMessages}
                    touched={true}
                />,
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-invalid', 'true');
        });

        it('should not have aria-invalid when field is not touched', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={mockValidationMessages}
                    touched={false}
                />,
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).not.toHaveAttribute('aria-invalid', 'true');
        });

        it('should link help text via aria-describedby', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    helpText="Help text"
                />,
            );

            const trigger = screen.getByRole('combobox');
            const describedBy = trigger.getAttribute('aria-describedby');
            expect(describedBy).toContain('test-select-help');
        });

        it('should link validation feedback via aria-describedby when touched', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    validationMessages={mockValidationMessages}
                    touched={true}
                />,
            );

            const trigger = screen.getByRole('combobox');
            const describedBy = trigger.getAttribute('aria-describedby');
            expect(describedBy).toContain('test-select-feedback');
        });

        it('should use aria-labelledby for visible label', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                />,
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-labelledby', 'test-select-label');
            expect(trigger).not.toHaveAttribute('aria-label');
        });

        it('should use aria-label for hidden label', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    hideLabel
                />,
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-label', 'Test Label');
            expect(trigger).not.toHaveAttribute('aria-labelledby');
        });

        it('should have aria-required when required', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    required
                />,
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-required', 'true');
        });
    });

    describe('Event handlers', () => {
        it('should call onValueChange when selection changes', async () => {
            const user = userEvent.setup();
            const onValueChange = vi.fn();

            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={onValueChange}
                    options={mockOptions}
                />,
            );

            const trigger = screen.getByRole('combobox');
            await user.click(trigger);

            const option1 = await screen.findByRole('option', { name: 'Option 1' });
            await user.click(option1);

            expect(onValueChange).toHaveBeenCalledWith('option1');
        });

        it('should call onValidationBlur after value changes', async () => {
            const user = userEvent.setup();
            const onValidationBlur = vi.fn();
            const onValueChange = vi.fn();

            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={onValueChange}
                    onValidationBlur={onValidationBlur}
                    options={mockOptions}
                />,
            );

            const trigger = screen.getByRole('combobox');
            await user.click(trigger);

            const option1 = await screen.findByRole('option', { name: 'Option 1' });
            await user.click(option1);

            // Wait for setTimeout in handleValueChange
            await vi.waitFor(() => {
                expect(onValidationBlur).toHaveBeenCalledTimes(1);
            });
        });
    });

    describe('Styling', () => {
        it('should apply custom className to container', () => {
            const { container } = render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    className="custom-class"
                />,
            );

            const wrapper = container.firstChild as HTMLElement;
            expect(wrapper).toHaveClass('custom-class');
        });

        it('should apply triggerClassName to trigger element', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    triggerClassName="custom-trigger-class"
                />,
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveClass('custom-trigger-class');
        });

        it('should merge containerProps className', () => {
            const { container } = render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    containerProps={{ className: 'container-class' }}
                />,
            );

            const wrapper = container.firstChild as HTMLElement;
            expect(wrapper).toHaveClass('container-class');
        });
    });

    describe('Options rendering', () => {
        it('should render all options', async () => {
            const user = userEvent.setup();

            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                />,
            );

            const trigger = screen.getByRole('combobox');
            await user.click(trigger);

            expect(await screen.findByRole('option', { name: 'Option 1' })).toBeInTheDocument();
            expect(await screen.findByRole('option', { name: 'Option 2' })).toBeInTheDocument();
            expect(await screen.findByRole('option', { name: 'Option 3' })).toBeInTheDocument();
        });

        it('should handle empty options array', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={[]}
                />,
            );

            expect(screen.getByRole('combobox')).toBeInTheDocument();
        });
    });

    describe('Selected value', () => {
        it('should display selected option label', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value="option2"
                    onValueChange={vi.fn()}
                    options={mockOptions}
                />,
            );

            expect(screen.getByText('Option 2')).toBeInTheDocument();
        });

        it('should show placeholder when no value selected', () => {
            render(
                <SelectField
                    id="test-select"
                    label="Test Label"
                    value=""
                    onValueChange={vi.fn()}
                    options={mockOptions}
                    placeholder="Choose"
                />,
            );

            expect(screen.getByText('Choose')).toBeInTheDocument();
        });
    });
});
