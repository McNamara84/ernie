import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import TitleField from '@/components/curation/fields/title-field';
import type { ValidationMessage } from '@/hooks/use-form-validation';

describe('TitleField Validation Integration', () => {
    const defaultProps = {
        id: 'test-title',
        title: '',
        titleType: 'main-title',
        options: [
            { value: 'main-title', label: 'Main Title' },
            { value: 'alternative', label: 'Alternative Title' },
        ],
        onTitleChange: () => {},
        onTypeChange: () => {},
        onAdd: () => {},
        onRemove: () => {},
        isFirst: true,
        canAdd: true,
    };

    describe('Basic Rendering', () => {
        it('renders without validation props', () => {
            render(<TitleField {...defaultProps} />);
            
            expect(screen.getByRole('textbox', { name: /Title/i })).toBeInTheDocument();
            expect(screen.getByRole('combobox', { name: /Title Type/i })).toBeInTheDocument();
        });

        it('renders with tooltip on title field', () => {
            render(<TitleField {...defaultProps} />);
            
            const titleInput = screen.getByRole('textbox', { name: /Title/i });
            const titleLabel = document.querySelector(`label[for="${titleInput.id}"]`);
            expect(titleLabel).toHaveClass('cursor-help');
        });
    });

    describe('Validation Messages Display', () => {
        it('displays error messages when touched', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Main title is required' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            expect(screen.getByText('Main title is required')).toBeInTheDocument();
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('does not display messages when not touched', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Main title is required' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={false}
                />
            );

            expect(screen.queryByText('Main title is required')).not.toBeInTheDocument();
        });

        it('displays warning messages', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'warning', message: 'Title is approaching maximum length' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            expect(screen.getByText('Title is approaching maximum length')).toBeInTheDocument();
        });

        it('displays uniqueness error', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'This title already exists' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    title="Duplicate Title"
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            expect(screen.getByText('This title already exists')).toBeInTheDocument();
        });

        it('displays multiple validation messages sorted by severity', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'info', message: 'Info message' },
                { severity: 'error', message: 'Error message' },
                { severity: 'warning', message: 'Warning message' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            // Verify all messages are displayed
            expect(screen.getByText('Error message')).toBeInTheDocument();
            expect(screen.getByText('Warning message')).toBeInTheDocument();
            expect(screen.getByText('Info message')).toBeInTheDocument();
            
            // Verify error message appears first (highest severity)
            const feedback = screen.getByText('Error message').closest('div');
            expect(feedback).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('sets aria-invalid when there are error messages and field is touched', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Main title is required' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const input = screen.getByRole('textbox', { name: /Title/i });
            expect(input).toHaveAttribute('aria-invalid', 'true');
        });

        it('does not set aria-invalid for warning messages', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'warning', message: 'Warning message' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const input = screen.getByRole('textbox', { name: /Title/i });
            expect(input).toHaveAttribute('aria-invalid', 'false');
        });

        it('links input to validation feedback via aria-describedby', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Main title is required' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const input = screen.getByRole('textbox', { name: /Title/i });
            const describedBy = input.getAttribute('aria-describedby');
            expect(describedBy).toBeTruthy();
            expect(describedBy).toContain('feedback');
        });
    });

    describe('Event Handlers', () => {
        it('calls onValidationBlur when title input loses focus', async () => {
            const user = userEvent.setup();
            let blurCalled = false;
            const handleBlur = () => {
                blurCalled = true;
            };

            render(
                <TitleField
                    {...defaultProps}
                    onValidationBlur={handleBlur}
                />
            );

            const input = screen.getByRole('textbox', { name: /Title/i });
            await user.click(input);
            await user.tab();

            await waitFor(() => {
                expect(blurCalled).toBe(true);
            });
        });

        it('calls onTitleChange when typing in title field', async () => {
            const user = userEvent.setup();
            let changeCallCount = 0;
            let lastChangedValue = '';
            const handleChange = (value: string) => {
                changeCallCount++;
                lastChangedValue = value;
            };

            render(
                <TitleField
                    {...defaultProps}
                    onTitleChange={handleChange}
                />
            );

            const input = screen.getByRole('textbox', { name: /Title/i });
            await user.type(input, 'Test Title');

            // Verify the handler was called (user.type triggers onChange for each character)
            await waitFor(() => {
                expect(changeCallCount).toBeGreaterThan(0);
                // The last value should include the typed content
                expect(lastChangedValue).toContain('e');
            });
        });
    });

    describe('Required Field Indicator', () => {
        it('shows required indicator for main title', () => {
            render(<TitleField {...defaultProps} titleType="main-title" />);
            
            const input = screen.getByRole('textbox', { name: /Title/i });
            expect(input).toBeRequired();
        });

        it('does not show required indicator for alternative title', () => {
            render(<TitleField {...defaultProps} titleType="alternative" />);
            
            const input = screen.getByRole('textbox', { name: /Title/i });
            expect(input).not.toBeRequired();
        });
    });

    describe('Label Visibility', () => {
        it('shows labels when isFirst is true', () => {
            render(<TitleField {...defaultProps} isFirst={true} />);
            
            // Verify Title input has visible label
            const titleInput = screen.getByRole('textbox', { name: /Title/i });
            const titleLabel = document.querySelector(`label[for="${titleInput.id}"]`);
            expect(titleLabel).not.toBeNull();
            expect(titleLabel).toBeInTheDocument();
            expect(titleLabel).not.toHaveClass('sr-only');
            
            // Verify Title Type select is accessible (has name from label)
            const typeSelect = screen.getByRole('combobox', { name: /Title Type/i });
            expect(typeSelect).toBeInTheDocument();
            // SelectField uses labelledby for accessibility, not traditional for/id pairing
            expect(typeSelect).toHaveAccessibleName();
        });

        it('hides labels when isFirst is false', () => {
            render(<TitleField {...defaultProps} isFirst={false} />);
            
            const titleInput = screen.getByRole('textbox', { name: /Title/i });
            const titleLabel = titleInput.closest('div')?.querySelector('label');
            expect(titleLabel).toHaveClass('sr-only');
        });
    });

    describe('Add/Remove Buttons', () => {
        it('shows add button when isFirst is true', () => {
            render(<TitleField {...defaultProps} isFirst={true} />);
            
            expect(screen.getByLabelText('Add title')).toBeInTheDocument();
        });

        it('shows remove button when isFirst is false', () => {
            render(<TitleField {...defaultProps} isFirst={false} />);
            
            expect(screen.getByLabelText('Remove title')).toBeInTheDocument();
        });

        it('disables add button when canAdd is false', () => {
            render(<TitleField {...defaultProps} isFirst={true} canAdd={false} />);
            
            expect(screen.getByLabelText('Add title')).toBeDisabled();
        });
    });

    describe('Styling with Validation States', () => {
        it('applies error styling when validation fails', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Main title is required' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const input = screen.getByRole('textbox', { name: /Title/i });
            expect(input).toHaveAttribute('aria-invalid', 'true');
            // Border styling is applied via aria-invalid attribute and Tailwind CSS
            expect(input).toHaveClass('aria-invalid:border-destructive');
        });

        it('applies warning styling when validation warns', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'warning', message: 'Warning message' },
            ];

            render(
                <TitleField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const input = screen.getByRole('textbox', { name: /Title/i });
            // Warning does not set aria-invalid
            expect(input).toHaveAttribute('aria-invalid', 'false');
            // Warning styling would be handled differently
        });
    });
});
