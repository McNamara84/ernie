import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DateField from '@/components/curation/fields/date-field';

describe('DateField', () => {
    const mockOnStartDateChange = vi.fn();
    const mockOnEndDateChange = vi.fn();
    const mockOnTypeChange = vi.fn();
    const mockOnAdd = vi.fn();
    const mockOnRemove = vi.fn();

    const defaultOptions = [
        { value: 'created', label: 'Created' },
        { value: 'valid', label: 'Valid' },
        { value: 'available', label: 'Available' },
        { value: 'submitted', label: 'Submitted' },
    ];

    const defaultProps = {
        id: 'test-date',
        startDate: '',
        endDate: '',
        dateType: 'created',
        options: defaultOptions,
        onStartDateChange: mockOnStartDateChange,
        onEndDateChange: mockOnEndDateChange,
        onTypeChange: mockOnTypeChange,
        onAdd: mockOnAdd,
        onRemove: mockOnRemove,
        isFirst: true,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders date picker component', () => {
        render(<DateField {...defaultProps} />);

        // DatePicker renders a combobox trigger (Button with role="combobox")
        // Find by role, excluding the date type select combobox
        const comboboxes = screen.getAllByRole('combobox');
        // At least one should be the date picker (the other is the date type select)
        expect(comboboxes.length).toBeGreaterThanOrEqual(1);
    });

    it('shows Date label for non-range date types', () => {
        render(<DateField {...defaultProps} dateType="created" />);

        expect(screen.getByText('Date')).toBeInTheDocument();
    });

    it('shows Start Date and End Date labels for valid date type', () => {
        render(<DateField {...defaultProps} dateType="valid" />);

        expect(screen.getByText('Start Date')).toBeInTheDocument();
        expect(screen.getByText('End Date')).toBeInTheDocument();
    });

    it('hides End Date field for non-valid date types', () => {
        render(<DateField {...defaultProps} dateType="created" />);

        expect(screen.queryByText('End Date')).not.toBeInTheDocument();
    });

    it('renders date type select with options', async () => {
        render(<DateField {...defaultProps} />);

        // Find all comboboxes - one is DatePicker, one is SelectField for date type
        const comboboxes = screen.getAllByRole('combobox');
        // The date type select should show "Created" as the current value
        const dateTypeSelect = comboboxes.find((cb) => cb.textContent?.includes('Created'));
        expect(dateTypeSelect).toBeInTheDocument();
    });

    it('displays prefilled start date in button text', () => {
        render(<DateField {...defaultProps} startDate="2024-06-15" />);

        // DatePicker shows the selected date in the button
        expect(screen.getByText(/2024-06-15/)).toBeInTheDocument();
    });

    it('displays prefilled end date for valid type', () => {
        render(<DateField {...defaultProps} dateType="valid" endDate="2024-12-31" />);

        expect(screen.getByText(/2024-12-31/)).toBeInTheDocument();
    });

    it('marks date as required for created type', () => {
        render(<DateField {...defaultProps} dateType="created" />);

        // The asterisk indicates required
        const label = screen.getByText('Date');
        const requiredIndicator = label.parentElement?.querySelector('.text-destructive');
        expect(requiredIndicator).toBeInTheDocument();
    });

    it('hides labels when isFirst is false', () => {
        render(<DateField {...defaultProps} isFirst={false} />);

        expect(screen.queryByText('Date')).not.toBeInTheDocument();
    });

    it('renders add button when isFirst is true', () => {
        render(<DateField {...defaultProps} isFirst={true} />);

        expect(screen.getByRole('button', { name: /add date/i })).toBeInTheDocument();
    });

    it('renders remove button when isFirst is false', () => {
        render(<DateField {...defaultProps} isFirst={false} />);

        expect(screen.getByRole('button', { name: /remove date/i })).toBeInTheDocument();
    });

    it('calls onAdd when add button is clicked', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} isFirst={true} />);

        await user.click(screen.getByRole('button', { name: /add date/i }));

        expect(mockOnAdd).toHaveBeenCalled();
    });

    it('calls onRemove when remove button is clicked', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} isFirst={false} />);

        await user.click(screen.getByRole('button', { name: /remove date/i }));

        expect(mockOnRemove).toHaveBeenCalled();
    });

    it('disables add button when canAdd is false', () => {
        render(<DateField {...defaultProps} isFirst={true} canAdd={false} />);

        expect(screen.getByRole('button', { name: /add date/i })).toBeDisabled();
    });

    it('calls onTypeChange when date type is changed', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} />);

        // Find all comboboxes and identify the date type select
        const comboboxes = screen.getAllByRole('combobox');
        const dateTypeSelect = comboboxes.find((cb) => cb.textContent?.includes('Created'));
        expect(dateTypeSelect).toBeTruthy();

        await user.click(dateTypeSelect!);

        // Wait for options to be visible and click "Valid"
        const validOption = await screen.findByRole('option', { name: 'Valid' });
        await user.click(validOption);

        expect(mockOnTypeChange).toHaveBeenCalledWith('valid');
    });

    it('shows two date pickers for valid date type', () => {
        render(<DateField {...defaultProps} dateType="valid" />);

        // For "valid" type, there should be multiple comboboxes:
        // - Start date picker (combobox)
        // - End date picker (combobox)
        // - Date type select (combobox)
        const comboboxes = screen.getAllByRole('combobox');
        // Filter to find date pickers by looking for "Select date" placeholder text
        const datePickerComboboxes = comboboxes.filter((cb) => cb.textContent?.includes('Select date') || /\d{4}-\d{2}-\d{2}/.test(cb.textContent || ''));
        expect(datePickerComboboxes.length).toBeGreaterThanOrEqual(2);
    });

    it('shows one date picker for non-valid date types', () => {
        render(<DateField {...defaultProps} dateType="created" />);

        // For non-valid types, there should be:
        // - One date picker (combobox)
        // - One date type select (combobox)
        // - Add/remove button (button)
        const comboboxes = screen.getAllByRole('combobox');
        // Filter to find date pickers by looking for "Select date" placeholder text
        const datePickerComboboxes = comboboxes.filter((cb) => cb.textContent?.includes('Select date') || /\d{4}-\d{2}-\d{2}/.test(cb.textContent || ''));
        expect(datePickerComboboxes.length).toBe(1);
    });

    it('displays date type description when provided', () => {
        render(<DateField {...defaultProps} dateTypeDescription="This is when the resource was created" />);

        expect(screen.getByText('This is when the resource was created')).toBeInTheDocument();
    });
});
