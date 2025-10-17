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
            
            expect(screen.getByLabelText('Title')).toBeInTheDocument();
            expect(screen.getByLabelText('Title Type')).toBeInTheDocument();
        });

        it('renders with tooltip on title field', () => {
            render(<TitleField {...defaultProps} />);
            
            const titleLabel = screen.getByText('Title').closest('label');
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

            const messages = screen.getAllByRole('listitem');
            expect(messages[0]).toHaveTextContent('Error message');
            expect(messages[1]).toHaveTextContent('Warning message');
            expect(messages[2]).toHaveTextContent('Info message');
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

            const input = screen.getByLabelText('Title');
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

            const input = screen.getByLabelText('Title');
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

            const input = screen.getByLabelText('Title');
            const describedBy = input.getAttribute('aria-describedby');
            expect(describedBy).toBeTruthy();
            expect(describedBy).toContain('validation-feedback');
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

            const input = screen.getByLabelText('Title');
            await user.click(input);
            await user.tab();

            await waitFor(() => {
                expect(blurCalled).toBe(true);
            });
        });

        it('calls onTitleChange when typing in title field', async () => {
            const user = userEvent.setup();
            let changedValue = '';
            const handleChange = (value: string) => {
                changedValue = value;
            };

            render(
                <TitleField
                    {...defaultProps}
                    onTitleChange={handleChange}
                />
            );

            const input = screen.getByLabelText('Title');
            await user.type(input, 'Test Title');

            expect(changedValue).toBe('Test Title');
        });
    });

    describe('Required Field Indicator', () => {
        it('shows required indicator for main title', () => {
            render(<TitleField {...defaultProps} titleType="main-title" />);
            
            const input = screen.getByLabelText('Title');
            expect(input).toBeRequired();
        });

        it('does not show required indicator for alternative title', () => {
            render(<TitleField {...defaultProps} titleType="alternative" />);
            
            const input = screen.getByLabelText('Title');
            expect(input).not.toBeRequired();
        });
    });

    describe('Label Visibility', () => {
        it('shows labels when isFirst is true', () => {
            render(<TitleField {...defaultProps} isFirst={true} />);
            
            expect(screen.getByText('Title')).toBeVisible();
            expect(screen.getByText('Title Type')).toBeVisible();
        });

        it('hides labels when isFirst is false', () => {
            render(<TitleField {...defaultProps} isFirst={false} />);
            
            const titleInput = screen.getByLabelText('Title');
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

            const input = screen.getByLabelText('Title');
            expect(input).toHaveClass('border-destructive');
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

            const input = screen.getByLabelText('Title');
            expect(input).toHaveClass('border-amber-500');
        });
    });
});
