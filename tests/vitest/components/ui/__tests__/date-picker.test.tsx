import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { format } from 'date-fns';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DatePicker } from '@/components/ui/date-picker';

describe('DatePicker', () => {
    const testDate = new Date(2026, 0, 15); // Jan 15, 2026

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('renders with placeholder when no value', () => {
            render(<DatePicker placeholder="Pick a date" />);
            expect(screen.getByText('Pick a date')).toBeInTheDocument();
        });

        it('renders with default placeholder', () => {
            render(<DatePicker />);
            expect(screen.getByText('Pick a date')).toBeInTheDocument();
        });

        it('renders formatted date when value is provided', () => {
            render(<DatePicker value={testDate} />);
            const formatted = format(testDate, 'PPP');
            expect(screen.getByText(formatted)).toBeInTheDocument();
        });

        it('renders with custom date format', () => {
            render(<DatePicker value={testDate} dateFormat="yyyy-MM-dd" />);
            expect(screen.getByText('2026-01-15')).toBeInTheDocument();
        });

        it('renders trigger button with combobox role', () => {
            render(<DatePicker />);
            expect(screen.getByRole('combobox')).toBeInTheDocument();
        });

        it('applies custom id', () => {
            render(<DatePicker id="my-datepicker" />);
            expect(screen.getByRole('combobox')).toHaveAttribute('id', 'my-datepicker');
        });
    });

    describe('disabled state', () => {
        it('disables button when disabled prop is true', () => {
            render(<DatePicker disabled />);
            expect(screen.getByRole('combobox')).toBeDisabled();
        });
    });

    describe('error state', () => {
        it('sets aria-invalid when error is true', () => {
            render(<DatePicker error />);
            expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
        });

        it('applies destructive border class on error', () => {
            render(<DatePicker error />);
            expect(screen.getByRole('combobox').className).toContain('border-destructive');
        });
    });

    describe('required state', () => {
        it('sets aria-required when required is true', () => {
            render(<DatePicker required />);
            expect(screen.getByRole('combobox')).toHaveAttribute('aria-required', 'true');
        });
    });

    describe('clear button', () => {
        it('shows clear button when clearable and value is set', () => {
            render(<DatePicker value={testDate} clearable />);
            expect(screen.getByLabelText('Clear date')).toBeInTheDocument();
        });

        it('does not show clear button when clearable is false', () => {
            render(<DatePicker value={testDate} clearable={false} />);
            expect(screen.queryByLabelText('Clear date')).not.toBeInTheDocument();
        });

        it('does not show clear button when no value', () => {
            render(<DatePicker clearable />);
            expect(screen.queryByLabelText('Clear date')).not.toBeInTheDocument();
        });

        it('calls onChange with undefined when clear is clicked', async () => {
            const onChange = vi.fn();
            render(<DatePicker value={testDate} onChange={onChange} clearable />);

            await userEvent.click(screen.getByLabelText('Clear date'));
            expect(onChange).toHaveBeenCalledWith(undefined);
        });
    });

    describe('hidden input', () => {
        it('renders hidden input when name is provided', () => {
            const { container } = render(<DatePicker name="date_field" value={testDate} />);
            const hiddenInput = container.querySelector('input[type="hidden"]');
            expect(hiddenInput).toBeInTheDocument();
            expect(hiddenInput).toHaveAttribute('name', 'date_field');
            expect(hiddenInput).toHaveAttribute('value', '2026-01-15');
        });

        it('renders empty hidden input when name is provided but no value', () => {
            const { container } = render(<DatePicker name="date_field" />);
            const hiddenInput = container.querySelector('input[type="hidden"]');
            expect(hiddenInput).toBeInTheDocument();
            expect(hiddenInput).toHaveAttribute('value', '');
        });

        it('does not render hidden input when no name', () => {
            const { container } = render(<DatePicker value={testDate} />);
            const hiddenInput = container.querySelector('input[type="hidden"]');
            expect(hiddenInput).not.toBeInTheDocument();
        });
    });

    describe('popover interaction', () => {
        it('opens calendar when trigger is clicked', async () => {
            render(<DatePicker />);
            const trigger = screen.getByRole('combobox');

            await userEvent.click(trigger);
            expect(trigger).toHaveAttribute('aria-expanded', 'true');
        });
    });

    describe('className', () => {
        it('applies additional className to trigger button', () => {
            render(<DatePicker className="my-custom-class" />);
            expect(screen.getByRole('combobox').className).toContain('my-custom-class');
        });
    });
});
