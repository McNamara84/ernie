import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DateField from '@/components/curation/fields/date-field';

describe('DateField', () => {
    const mockOnStartDateChange = vi.fn();
    const mockOnEndDateChange = vi.fn();
    const mockOnTypeChange = vi.fn();
    const mockOnDateModeChange = vi.fn();
    const mockOnAdd = vi.fn();
    const mockOnRemove = vi.fn();
    const mockOnStartTimeChange = vi.fn();
    const mockOnEndTimeChange = vi.fn();
    const mockOnStartTimezoneChange = vi.fn();
    const mockOnEndTimezoneChange = vi.fn();

    const defaultOptions = [
        { value: 'created', label: 'Created' },
        { value: 'valid', label: 'Valid' },
        { value: 'collected', label: 'Collected' },
        { value: 'other', label: 'Other' },
        { value: 'available', label: 'Available' },
        { value: 'submitted', label: 'Submitted' },
    ];

    const defaultProps = {
        id: 'test-date',
        startDate: '',
        endDate: '',
        dateType: 'created',
        dateMode: 'single' as const,
        options: defaultOptions,
        onStartDateChange: mockOnStartDateChange,
        onEndDateChange: mockOnEndDateChange,
        onTypeChange: mockOnTypeChange,
        onDateModeChange: mockOnDateModeChange,
        onAdd: mockOnAdd,
        onRemove: mockOnRemove,
        isFirst: true,
        startTime: null as string | null,
        endTime: null as string | null,
        startTimezone: null as string | null,
        endTimezone: null as string | null,
        onStartTimeChange: mockOnStartTimeChange,
        onEndTimeChange: mockOnEndTimeChange,
        onStartTimezoneChange: mockOnStartTimezoneChange,
        onEndTimezoneChange: mockOnEndTimezoneChange,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders date picker component', () => {
        render(<DateField {...defaultProps} />);

        const comboboxes = screen.getAllByRole('combobox');
        expect(comboboxes.length).toBeGreaterThanOrEqual(1);
    });

    it('shows Date label for single-date mode', () => {
        render(<DateField {...defaultProps} dateType="collected" dateMode="single" />);

        expect(screen.getByText('Date')).toBeInTheDocument();
    });

    it('shows Start Date and End Date labels for period mode', () => {
        render(<DateField {...defaultProps} dateType="valid" dateMode="range" />);

        expect(screen.getByText('Start Date')).toBeInTheDocument();
        expect(screen.getByText('End Date')).toBeInTheDocument();
    });

    it.each(['collected', 'valid', 'other'])('shows the date-mode toggle for %s dates', (dateType) => {
        render(<DateField {...defaultProps} dateType={dateType} />);

        expect(screen.getByRole('group', { name: /date mode/i })).toBeInTheDocument();
        expect(screen.getByText('Single date')).toBeInTheDocument();
        expect(screen.getByText('Period')).toBeInTheDocument();
    });

    it('hides date-mode toggle and End Date field for non-period date types', () => {
        render(<DateField {...defaultProps} dateType="available" />);

        expect(screen.queryByRole('group', { name: /date mode/i })).not.toBeInTheDocument();
        expect(screen.queryByText('End Date')).not.toBeInTheDocument();
    });

    it('calls onDateModeChange when period mode is selected', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} dateType="valid" dateMode="single" />);

        await user.click(screen.getByText('Period'));

        expect(mockOnDateModeChange).toHaveBeenCalledWith('range');
    });

    it('renders date type select with options', async () => {
        render(<DateField {...defaultProps} />);

        const comboboxes = screen.getAllByRole('combobox');
        const dateTypeSelect = comboboxes.find((cb) => cb.textContent?.includes('Created'));
        expect(dateTypeSelect).toBeInTheDocument();
    });

    it('displays prefilled start date in button text', () => {
        render(<DateField {...defaultProps} startDate="2024-06-15" />);

        expect(screen.getByText(/2024-06-15/)).toBeInTheDocument();
    });

    it('displays prefilled end date in period mode', () => {
        render(<DateField {...defaultProps} dateType="valid" dateMode="range" endDate="2024-12-31" />);

        expect(screen.getByText(/2024-12-31/)).toBeInTheDocument();
    });

    it('marks date as required for created type', () => {
        render(<DateField {...defaultProps} dateType="created" />);

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

        const comboboxes = screen.getAllByRole('combobox');
        const dateTypeSelect = comboboxes.find((cb) => cb.textContent?.includes('Created'));
        expect(dateTypeSelect).toBeTruthy();

        await user.click(dateTypeSelect!);

        const validOption = await screen.findByRole('option', { name: 'Valid' });
        await user.click(validOption);

        expect(mockOnTypeChange).toHaveBeenCalledWith('valid');
    });

    it('shows two date pickers for period mode', () => {
        render(<DateField {...defaultProps} dateType="valid" dateMode="range" />);

        const comboboxes = screen.getAllByRole('combobox');
        const datePickerComboboxes = comboboxes.filter(
            (cb) => cb.textContent?.includes('Select date') || /\d{4}-\d{2}-\d{2}/.test(cb.textContent || ''),
        );
        expect(datePickerComboboxes.length).toBeGreaterThanOrEqual(2);
    });

    it('shows one date picker for single-date mode', () => {
        render(<DateField {...defaultProps} dateType="valid" dateMode="single" />);

        const comboboxes = screen.getAllByRole('combobox');
        const datePickerComboboxes = comboboxes.filter(
            (cb) => cb.textContent?.includes('Select date') || /\d{4}-\d{2}-\d{2}/.test(cb.textContent || ''),
        );
        expect(datePickerComboboxes.length).toBe(1);
    });

    it('displays date type description when provided', () => {
        render(<DateField {...defaultProps} dateTypeDescription="This is when the resource was created" />);

        expect(screen.getByText('This is when the resource was created')).toBeInTheDocument();
    });
});
