'use client';

import { format } from 'date-fns';
import { Calendar as CalendarIcon, X } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface DatePickerProps {
    /** Selected date value */
    value?: Date;
    /** Callback when date changes */
    onChange?: (date: Date | undefined) => void;
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
    /** Name for form association */
    name?: string;
    /** Required field */
    required?: boolean;
    /** Error state */
    error?: boolean;
    /** Align popover */
    align?: 'start' | 'center' | 'end';
}

/**
 * DatePicker Component
 *
 * A date picker built on shadcn/ui Calendar and Popover components.
 * Supports single date selection with optional clear functionality.
 *
 * @example
 * ```tsx
 * const [date, setDate] = useState<Date>();
 *
 * <DatePicker
 *     value={date}
 *     onChange={setDate}
 *     placeholder="Select a date"
 * />
 * ```
 *
 * @example With date constraints
 * ```tsx
 * <DatePicker
 *     value={date}
 *     onChange={setDate}
 *     minDate={new Date()}
 *     maxDate={addYears(new Date(), 1)}
 * />
 * ```
 */
export function DatePicker({
    value,
    onChange,
    placeholder = 'Pick a date',
    dateFormat = 'PPP',
    disabled = false,
    clearable = true,
    minDate,
    maxDate,
    className,
    id,
    name,
    required,
    error,
    align = 'start',
}: DatePickerProps) {
    const [open, setOpen] = React.useState(false);

    const handleSelect = (date: Date | undefined) => {
        onChange?.(date);
        setOpen(false);
    };

    const handleClear = (e: React.MouseEvent) => {
        e.stopPropagation();
        onChange?.(undefined);
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
                        !value && 'text-muted-foreground',
                        error && 'border-destructive',
                        className,
                    )}
                >
                    <CalendarIcon className="mr-2 h-4 w-4" />
                    {value ? format(value, dateFormat) : <span>{placeholder}</span>}
                    {clearable && value && (
                        <X className="ml-auto h-4 w-4 opacity-50 hover:opacity-100" onClick={handleClear} aria-label="Clear date" />
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align={align}>
                <Calendar
                    mode="single"
                    selected={value}
                    onSelect={handleSelect}
                    disabled={(date) => {
                        if (minDate && date < minDate) return true;
                        if (maxDate && date > maxDate) return true;
                        return false;
                    }}
                    initialFocus
                />
            </PopoverContent>
            {/* Hidden input for form submission */}
            {name && <input type="hidden" name={name} value={value ? format(value, 'yyyy-MM-dd') : ''} />}
        </Popover>
    );
}
