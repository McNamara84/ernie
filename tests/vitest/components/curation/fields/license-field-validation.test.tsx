import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import LicenseField from '@/components/curation/fields/license-field';
import type { ValidationMessage } from '@/hooks/use-form-validation';

describe('LicenseField Validation Integration', () => {
    const defaultProps = {
        id: 'test-license',
        license: '',
        options: [
            { value: 'cc-by-4.0', label: 'CC BY 4.0' },
            { value: 'cc0-1.0', label: 'CC0 1.0' },
            { value: 'mit', label: 'MIT License' },
        ],
        onLicenseChange: () => {},
        onAdd: () => {},
        onRemove: () => {},
        isFirst: true,
        canAdd: true,
        required: true,
    };

    describe('Basic Rendering', () => {
        it('renders without validation props', () => {
            render(<LicenseField {...defaultProps} />);
            
            expect(screen.getByLabelText(/^License/)).toBeInTheDocument();
        });

        it('renders with required indicator when required is true', () => {
            render(<LicenseField {...defaultProps} required={true} />);
            
            const select = screen.getByLabelText(/^License/);
            expect(select).toBeRequired();
        });

        it('does not show required indicator for secondary licenses', () => {
            render(<LicenseField {...defaultProps} isFirst={false} required={false} />);
            
            const select = screen.getByLabelText(/^License/);
            expect(select).not.toBeRequired();
        });
    });

    describe('Validation Messages Display', () => {
        it('displays error messages when touched', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Primary license is required' },
            ];

            render(
                <LicenseField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            expect(screen.getByText('Primary license is required')).toBeInTheDocument();
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('does not display messages when not touched', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Primary license is required' },
            ];

            render(
                <LicenseField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={false}
                />
            );

            expect(screen.queryByText('Primary license is required')).not.toBeInTheDocument();
        });

        it('displays multiple validation messages', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Primary license is required' },
                { severity: 'info', message: 'Select an appropriate license for your data' },
            ];

            render(
                <LicenseField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            expect(screen.getByText('Primary license is required')).toBeInTheDocument();
            expect(screen.getByText('Select an appropriate license for your data')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('sets aria-invalid when there are error messages and field is touched', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Primary license is required' },
            ];

            render(
                <LicenseField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-invalid', 'true');
        });

        it('does not set aria-invalid when valid', () => {
            render(
                <LicenseField
                    {...defaultProps}
                    license="cc-by-4.0"
                />
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-invalid', 'false');
        });

        it('links select to validation feedback via aria-describedby', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Primary license is required' },
            ];

            render(
                <LicenseField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const trigger = screen.getByRole('combobox');
            const describedBy = trigger.getAttribute('aria-describedby');
            expect(describedBy).toBeTruthy();
            expect(describedBy).toContain('feedback');
        });
    });

    describe('Event Handlers', () => {
        it('calls onValidationBlur when provided', async () => {
            const user = userEvent.setup({ pointerEventsCheck: 0 });
            let blurCalled = false;
            const handleBlur = () => {
                blurCalled = true;
            };

            render(
                <LicenseField
                    {...defaultProps}
                    onValidationBlur={handleBlur}
                />
            );

            const trigger = screen.getByRole('combobox');
            
            // SelectField triggers onValidationBlur in handleValueChange
            // So we select a value to trigger the blur handler
            await user.click(trigger);
            const option = await screen.findByText('CC BY 4.0');
            await user.click(option);

            await waitFor(() => {
                expect(blurCalled).toBe(true);
            });
        });

        it('calls onLicenseChange when selecting a license', async () => {
            const user = userEvent.setup();
            let selectedValue = '';
            const handleChange = (value: string) => {
                selectedValue = value;
            };

            render(
                <LicenseField
                    {...defaultProps}
                    onLicenseChange={handleChange}
                />
            );

            const trigger = screen.getByRole('combobox');
            await user.click(trigger);
            
            const option = await screen.findByText('MIT License');
            await user.click(option);

            expect(selectedValue).toBe('mit');
        });
    });

    describe('Add/Remove Buttons', () => {
        it('shows add button when isFirst is true and canAdd is true', () => {
            render(<LicenseField {...defaultProps} isFirst={true} canAdd={true} />);
            
            expect(screen.getByLabelText('Add license')).toBeInTheDocument();
        });

        it('shows remove button when isFirst is false', () => {
            render(<LicenseField {...defaultProps} isFirst={false} />);
            
            expect(screen.getByLabelText('Remove license')).toBeInTheDocument();
        });

        it('does not show add button when canAdd is false', () => {
            render(<LicenseField {...defaultProps} isFirst={true} canAdd={false} />);
            
            expect(screen.queryByLabelText('Add license')).not.toBeInTheDocument();
        });
    });

    describe('Styling with Validation States', () => {
        it('applies error styling when validation fails', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Primary license is required' },
            ];

            render(
                <LicenseField
                    {...defaultProps}
                    validationMessages={validationMessages}
                    touched={true}
                />
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-invalid', 'true');
            // Border styling is applied via aria-invalid attribute and Tailwind CSS
            expect(trigger).toHaveClass('aria-invalid:border-destructive');
        });

        it('applies normal styling when no validation errors', () => {
            render(
                <LicenseField
                    {...defaultProps}
                    license="cc-by-4.0"
                />
            );

            const trigger = screen.getByRole('combobox');
            expect(trigger).not.toHaveClass('border-destructive');
        });
    });

    describe('Label Visibility', () => {
        it('shows label when isFirst is true', () => {
            render(<LicenseField {...defaultProps} isFirst={true} />);
            
            expect(screen.getByText('License')).toBeVisible();
        });

        it('hides label visually when isFirst is false', () => {
            render(<LicenseField {...defaultProps} isFirst={false} />);
            
            const label = screen.getByText('License').closest('label');
            expect(label).toHaveClass('sr-only');
        });
    });

    describe('Options Display', () => {
        it('displays all license options when opened', async () => {
            const user = userEvent.setup();
            
            render(<LicenseField {...defaultProps} />);

            const trigger = screen.getByRole('combobox');
            await user.click(trigger);

            expect(await screen.findByText('CC BY 4.0')).toBeInTheDocument();
            expect(await screen.findByText('CC0 1.0')).toBeInTheDocument();
            expect(await screen.findByText('MIT License')).toBeInTheDocument();
        });

        it('displays selected license value', () => {
            render(<LicenseField {...defaultProps} license="cc-by-4.0" />);

            // SelectField shows the label of the selected option
            expect(screen.getByText('CC BY 4.0')).toBeInTheDocument();
        });
    });
});
