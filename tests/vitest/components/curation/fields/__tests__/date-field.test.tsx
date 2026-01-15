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

    it('renders date input field', () => {
        render(<DateField {...defaultProps} />);

        // Use the ID-based selector for the date input
        expect(document.getElementById('test-date-date')).toBeInTheDocument();
    });

    it('shows Date label for non-range date types', () => {
        render(<DateField {...defaultProps} dateType="created" />);

        // Use the ID-based selector for the exact date label
        const dateLabel = document.getElementById('test-date-date-label');
        expect(dateLabel).toBeInTheDocument();
        expect(dateLabel).toHaveTextContent('Date');
    });

    it('shows Start Date and End Date labels for valid date type', () => {
        render(<DateField {...defaultProps} dateType="valid" />);

        expect(screen.getByLabelText('Start Date', { exact: false })).toBeInTheDocument();
        expect(screen.getByLabelText('End Date', { exact: false })).toBeInTheDocument();
    });

    it('hides End Date field for non-valid date types', () => {
        render(<DateField {...defaultProps} dateType="created" />);

        expect(screen.queryByLabelText('End Date', { exact: false })).not.toBeInTheDocument();
    });

    it('calls onStartDateChange when date is entered', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} />);

        const dateInput = document.getElementById('test-date-date') as HTMLInputElement;
        await user.type(dateInput, '2024-01-15');

        expect(mockOnStartDateChange).toHaveBeenCalled();
    });

    it('calls onEndDateChange when end date is entered', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} dateType="valid" />);

        const endDateInput = screen.getByLabelText('End Date', { exact: false });
        await user.type(endDateInput, '2024-12-31');

        expect(mockOnEndDateChange).toHaveBeenCalled();
    });

    it('renders date type select with options', () => {
        render(<DateField {...defaultProps} />);

        expect(screen.getByRole('combobox')).toBeInTheDocument();
    });

    it('displays prefilled start date', () => {
        render(<DateField {...defaultProps} startDate="2024-06-15" />);

        const dateInput = document.getElementById('test-date-date') as HTMLInputElement;
        expect(dateInput.value).toBe('2024-06-15');
    });

    it('displays prefilled end date for valid type', () => {
        render(<DateField {...defaultProps} dateType="valid" endDate="2024-12-31" />);

        const endDateInput = screen.getByLabelText('End Date', { exact: false }) as HTMLInputElement;
        expect(endDateInput.value).toBe('2024-12-31');
    });

    it('shows Add button when isFirst is true', () => {
        render(<DateField {...defaultProps} isFirst={true} />);

        expect(screen.getByRole('button', { name: /Add date/i })).toBeInTheDocument();
    });

    it('shows Remove button when isFirst is false', () => {
        render(<DateField {...defaultProps} isFirst={false} />);

        expect(screen.getByRole('button', { name: /Remove date/i })).toBeInTheDocument();
    });

    it('calls onAdd when Add button is clicked', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} isFirst={true} />);

        await user.click(screen.getByRole('button', { name: /Add date/i }));

        expect(mockOnAdd).toHaveBeenCalled();
    });

    it('calls onRemove when Remove button is clicked', async () => {
        const user = userEvent.setup();
        render(<DateField {...defaultProps} isFirst={false} />);

        await user.click(screen.getByRole('button', { name: /Remove date/i }));

        expect(mockOnRemove).toHaveBeenCalled();
    });

    it('disables Add button when canAdd is false', () => {
        render(<DateField {...defaultProps} isFirst={true} canAdd={false} />);

        expect(screen.getByRole('button', { name: /Add date/i })).toBeDisabled();
    });

    it('enables Add button by default', () => {
        render(<DateField {...defaultProps} isFirst={true} />);

        expect(screen.getByRole('button', { name: /Add date/i })).not.toBeDisabled();
    });

    it('displays date type description when provided', () => {
        render(<DateField {...defaultProps} dateTypeDescription="The date when resource was created" />);

        expect(screen.getByText('The date when resource was created')).toBeInTheDocument();
    });

    it('does not display description when not provided', () => {
        render(<DateField {...defaultProps} />);

        expect(screen.queryByText(/The date when/)).not.toBeInTheDocument();
    });

    it('marks date as required for created type', () => {
        render(<DateField {...defaultProps} dateType="created" />);

        const dateInput = document.getElementById('test-date-date') as HTMLInputElement;
        expect(dateInput).toHaveAttribute('required');
    });

    it('clears endDate when switching from valid to another type', () => {
        const { rerender } = render(<DateField {...defaultProps} dateType="valid" endDate="2024-12-31" />);

        // Switch from valid to created
        rerender(<DateField {...defaultProps} dateType="created" endDate="2024-12-31" />);

        expect(mockOnEndDateChange).toHaveBeenCalledWith('');
    });

    it('does not clear endDate when staying on valid type', () => {
        const { rerender } = render(<DateField {...defaultProps} dateType="valid" endDate="2024-12-31" />);

        // Stay on valid type
        rerender(<DateField {...defaultProps} dateType="valid" endDate="2024-12-31" />);

        expect(mockOnEndDateChange).not.toHaveBeenCalled();
    });

    it('applies custom className', () => {
        const { container } = render(<DateField {...defaultProps} className="custom-class" />);

        expect(container.firstChild).toHaveClass('custom-class');
    });

    it('hides labels when isFirst is false', () => {
        render(<DateField {...defaultProps} isFirst={false} />);

        // Labels should be visually hidden but still accessible
        const dateLabel = document.getElementById('test-date-date-label');
        expect(dateLabel).toHaveClass('sr-only');
    });
});
