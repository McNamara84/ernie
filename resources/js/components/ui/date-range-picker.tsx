'use client';

import { format } from 'date-fns';
import { Calendar as CalendarIcon, X } from 'lucide-react';
import * as React from 'react';
import type { DateRange } from 'react-day-picker';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface DateRangePickerProps {
    /** Selected date range value */
    value?: DateRange;
    /** Callback when date range changes */
    onChange?: (range: DateRange | undefined) => void;
    /** Placeholder text when no date selected */
    placeholder?: string;
    /** Date format string (date-fns format) */
    dateFormat?: string;
    /** Disable the date picker */
    disabled?: boolean;
    /** Show clear button */
    clearable?: boolean;
    /** Minimum selectable date */
    minDate?: Date;
    /** Maximum selectable date */
    maxDate?: Date;
    /** Additional class names for the trigger button */
    className?: string;
    /** ID for form association */
    id?: string;
    /** Names for form association [startName, endName] */
    names?: [string, string];
    /** Required field */
    required?: boolean;
    /** Error state */
    error?: boolean;
    /** Align popover */
    align?: 'start' | 'center' | 'end';
    /** Number of months to display */
    numberOfMonths?: number;
}

/**
 * DateRangePicker Component
 *
 * A date range picker built on shadcn/ui Calendar and Popover components.
 * Supports start and end date selection with optional clear functionality.
 *
 * @example
 * ```tsx
 * const [dateRange, setDateRange] = useState<DateRange>();
 *
 * <DateRangePicker
 *     value={dateRange}
 *     onChange={setDateRange}
 *     placeholder="Select date range"
 * />
 * ```
 *
 * @example With form names
 * ```tsx
 * <DateRangePicker
 *     value={dateRange}
 *     onChange={setDateRange}
 *     names={['start_date', 'end_date']}
 * />
 * ```
 */
export function DateRangePicker({
    value,
    onChange,
    placeholder = 'Pick a date range',
    dateFormat = 'LLL dd, y',
    disabled = false,
    clearable = true,
    minDate,
    maxDate,
    className,
    id,
    names,
    required,
    error,
    align = 'start',
    numberOfMonths = 2,
}: DateRangePickerProps) {
    const [open, setOpen] = React.useState(false);

    const handleSelect = (range: DateRange | undefined) => {
        onChange?.(range);
        // Keep open until both dates are selected
        if (range?.from && range?.to) {
            setOpen(false);
        }
    };

    const handleClear = (e: React.MouseEvent) => {
        e.stopPropagation();
        onChange?.(undefined);
    };

    const formatDateRange = () => {
        if (!value?.from) return null;
        if (!value.to) return format(value.from, dateFormat);
        return `${format(value.from, dateFormat)} - ${format(value.to, dateFormat)}`;
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-required={required}
                    aria-invalid={error}
                    disabled={disabled}
                    className={cn(
                        'w-full justify-start text-left font-normal',
                        !value?.from && 'text-muted-foreground',
                        error && 'border-destructive',
                        className,
                    )}
                >
                    <CalendarIcon className="mr-2 h-4 w-4" />
                    {formatDateRange() ?? <span>{placeholder}</span>}
                    {clearable && value?.from && (
                        <X className="ml-auto h-4 w-4 opacity-50 hover:opacity-100" onClick={handleClear} aria-label="Clear date range" />
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align={align}>
                <Calendar
                    mode="range"
                    defaultMonth={value?.from}
                    selected={value}
                    onSelect={handleSelect}
                    numberOfMonths={numberOfMonths}
                    disabled={(date) => {
                        if (minDate && date < minDate) return true;
                        if (maxDate && date > maxDate) return true;
                        return false;
                    }}
                    initialFocus
                />
            </PopoverContent>
            {/* Hidden inputs for form submission */}
            {names && (
                <>
                    <input type="hidden" name={names[0]} value={value?.from ? format(value.from, 'yyyy-MM-dd') : ''} />
                    <input type="hidden" name={names[1]} value={value?.to ? format(value.to, 'yyyy-MM-dd') : ''} />
                </>
            )}
        </Popover>
    );
}

// Re-export DateRange type for convenience
export type { DateRange } from 'react-day-picker';
