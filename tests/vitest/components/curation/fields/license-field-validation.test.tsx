import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

import LicenseField from '@/components/curation/fields/license-field';
import type { LicenseEntry } from '@/components/curation/types/datacite-form-types';
import type { ValidationMessage } from '@/hooks/use-form-validation';

const options = [
    { value: 'cc-by-4.0', label: 'CC BY 4.0' },
    { value: 'cc0-1.0', label: 'CC0 1.0' },
    { value: 'mit', label: 'MIT License' },
];

const catalogEntry = (license = ''): LicenseEntry => ({
    id: 'test-license',
    mode: 'catalog',
    license,
});

const customEntry = (name = '', uri = ''): LicenseEntry => ({
    id: 'test-license',
    mode: 'custom',
    name,
    uri,
});

const renderLicenseField = (props: Partial<ComponentProps<typeof LicenseField>> = {}) => {
    const defaultProps: ComponentProps<typeof LicenseField> = {
        id: 'test-license',
        entry: catalogEntry(),
        options,
        onModeChange: vi.fn(),
        onCatalogLicenseChange: vi.fn(),
        onCustomLicenseChange: vi.fn(),
        onAdd: vi.fn(),
        onRemove: vi.fn(),
        isFirst: true,
        canAdd: true,
        required: true,
    };

    return render(<LicenseField {...defaultProps} {...props} />);
};

describe('LicenseField Validation Integration', () => {
    describe('Basic Rendering', () => {
        it('renders catalog mode by default', () => {
            renderLicenseField();

            expect(screen.getByRole('button', { name: 'Catalog' })).toHaveAttribute('aria-pressed', 'true');
            expect(screen.getByRole('combobox')).toBeInTheDocument();
        });

        it('renders with required indicator when required is true', () => {
            renderLicenseField({ required: true });

            const select = screen.getByRole('combobox');
            expect(select).toHaveAttribute('aria-required', 'true');
        });

        it('does not mark secondary licenses as required', () => {
            renderLicenseField({ isFirst: false, required: false });

            const select = screen.getByRole('combobox');
            expect(select).not.toHaveAttribute('aria-required', 'true');
        });

        it('renders custom license inputs in custom mode', () => {
            renderLicenseField({ entry: customEntry() });

            expect(screen.getByRole('button', { name: 'Custom' })).toHaveAttribute('aria-pressed', 'true');
            expect(screen.getByRole('textbox', { name: /^License name/ })).toBeInTheDocument();
            expect(screen.getByRole('textbox', { name: /^License text URL/ })).toBeInTheDocument();
        });
    });

    describe('Validation Messages Display', () => {
        it('displays error messages when touched', () => {
            const validationMessages: ValidationMessage[] = [{ severity: 'error', message: 'Primary license is required' }];

            renderLicenseField({ validationMessages, touched: true });

            expect(screen.getByText('Primary license is required')).toBeInTheDocument();
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('does not display messages when not touched', () => {
            const validationMessages: ValidationMessage[] = [{ severity: 'error', message: 'Primary license is required' }];

            renderLicenseField({ validationMessages, touched: false });

            expect(screen.queryByText('Primary license is required')).not.toBeInTheDocument();
        });

        it('displays multiple validation messages', () => {
            const validationMessages: ValidationMessage[] = [
                { severity: 'error', message: 'Primary license is required' },
                { severity: 'info', message: 'Select an appropriate license for your data' },
            ];

            renderLicenseField({ validationMessages, touched: true });

            expect(screen.getByText('Primary license is required')).toBeInTheDocument();
            expect(screen.getByText('Select an appropriate license for your data')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('sets aria-invalid when catalog field has touched error messages', () => {
            const validationMessages: ValidationMessage[] = [{ severity: 'error', message: 'Primary license is required' }];

            renderLicenseField({ validationMessages, touched: true });

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-invalid', 'true');
        });

        it('does not set aria-invalid when catalog field is valid', () => {
            renderLicenseField({ entry: catalogEntry('cc-by-4.0') });

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-invalid', 'false');
        });

        it('links catalog select to validation feedback via aria-describedby', () => {
            const validationMessages: ValidationMessage[] = [{ severity: 'error', message: 'Primary license is required' }];

            renderLicenseField({ validationMessages, touched: true });

            const trigger = screen.getByRole('combobox');
            const describedBy = trigger.getAttribute('aria-describedby');
            expect(describedBy).toBeTruthy();
            expect(describedBy).toContain('feedback');
        });
    });

    describe('Event Handlers', () => {
        it('calls onValidationBlur when selecting a catalog license', async () => {
            const user = userEvent.setup({ pointerEventsCheck: 0 });
            const handleBlur = vi.fn();

            renderLicenseField({ onValidationBlur: handleBlur });

            await user.click(screen.getByRole('combobox'));
            await user.click(await screen.findByText('CC BY 4.0'));

            await waitFor(() => {
                expect(handleBlur).toHaveBeenCalled();
            });
        });

        it('calls onCatalogLicenseChange when selecting a license', async () => {
            const user = userEvent.setup();
            const handleChange = vi.fn();

            renderLicenseField({ onCatalogLicenseChange: handleChange });

            await user.click(screen.getByRole('combobox'));
            await user.click(await screen.findByText('MIT License'));

            expect(handleChange).toHaveBeenCalledWith('mit');
        });

        it('calls onModeChange when switching modes', async () => {
            const user = userEvent.setup();
            const handleModeChange = vi.fn();

            renderLicenseField({ onModeChange: handleModeChange });

            await user.click(screen.getByRole('button', { name: 'Custom' }));

            expect(handleModeChange).toHaveBeenCalledWith('custom');
        });

        it('calls onCustomLicenseChange for custom name and URL inputs', async () => {
            const user = userEvent.setup();
            const handleCustomChange = vi.fn();

            renderLicenseField({ entry: customEntry(), onCustomLicenseChange: handleCustomChange });

            await user.type(screen.getByRole('textbox', { name: /^License name/ }), 'Community License');
            await user.type(screen.getByRole('textbox', { name: /^License text URL/ }), 'https://example.test/license');

            expect(handleCustomChange).toHaveBeenCalledWith('name', expect.any(String));
            expect(handleCustomChange).toHaveBeenCalledWith('uri', expect.any(String));
        });
    });

    describe('Add/Remove Buttons', () => {
        it('shows add button when isFirst is true and canAdd is true', () => {
            renderLicenseField({ isFirst: true, canAdd: true });

            expect(screen.getByLabelText('Add license')).toBeInTheDocument();
        });

        it('shows remove button when isFirst is false', () => {
            renderLicenseField({ isFirst: false });

            expect(screen.getByLabelText('Remove license')).toBeInTheDocument();
        });

        it('does not show add button when canAdd is false', () => {
            renderLicenseField({ isFirst: true, canAdd: false });

            expect(screen.queryByLabelText('Add license')).not.toBeInTheDocument();
        });
    });

    describe('Styling with Validation States', () => {
        it('applies error styling when catalog validation fails', () => {
            const validationMessages: ValidationMessage[] = [{ severity: 'error', message: 'Primary license is required' }];

            renderLicenseField({ validationMessages, touched: true });

            const trigger = screen.getByRole('combobox');
            expect(trigger).toHaveAttribute('aria-invalid', 'true');
            expect(trigger).toHaveClass('aria-invalid:border-destructive');
        });

        it('applies normal styling when no validation errors exist', () => {
            renderLicenseField({ entry: catalogEntry('cc-by-4.0') });

            const trigger = screen.getByRole('combobox');
            expect(trigger).not.toHaveClass('border-destructive');
        });
    });

    describe('Label Visibility', () => {
        it('shows catalog label when isFirst is true', () => {
            renderLicenseField({ isFirst: true });

            expect(screen.getByText('License')).toBeVisible();
        });

        it('hides catalog label visually when isFirst is false', () => {
            renderLicenseField({ isFirst: false });

            const label = screen.getByText('License').closest('label');
            expect(label).toHaveClass('sr-only');
        });
    });

    describe('Options Display', () => {
        it('displays all catalog license options when opened', async () => {
            const user = userEvent.setup();

            renderLicenseField();

            await user.click(screen.getByRole('combobox'));

            expect(await screen.findByText('CC BY 4.0')).toBeInTheDocument();
            expect(await screen.findByText('CC0 1.0')).toBeInTheDocument();
            expect(await screen.findByText('MIT License')).toBeInTheDocument();
        });

        it('displays selected catalog license value', () => {
            renderLicenseField({ entry: catalogEntry('cc-by-4.0') });

            expect(screen.getByText('CC BY 4.0')).toBeInTheDocument();
        });
    });
});