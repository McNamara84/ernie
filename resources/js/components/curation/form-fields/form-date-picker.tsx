/**
 * Form DatePicker Component
 *
 * A form-integrated date picker field with label, description, and error handling.
 */

import { format } from 'date-fns';
import type { Control, FieldPath, FieldValues } from 'react-hook-form';

import { DatePicker } from '@/components/ui/date-picker';
import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';

export interface FormDatePickerProps<TFieldValues extends FieldValues = FieldValues> {
    control: Control<TFieldValues>;
    name: FieldPath<TFieldValues>;
    label: string;
    description?: string;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    /** Date format for display (default: yyyy-MM-dd) */
    dateFormat?: string;
    /** Minimum selectable date */
    minDate?: Date;
    /** Maximum selectable date */
    maxDate?: Date;
}

export function FormDatePicker<TFieldValues extends FieldValues = FieldValues>({
    control,
    name,
    label,
    description,
    placeholder = 'Select date',
    disabled = false,
    className,
    dateFormat = 'yyyy-MM-dd',
    minDate,
    maxDate,
}: FormDatePickerProps<TFieldValues>) {
    return (
        <FormField
            control={control}
            name={name}
            render={({ field }) => {
                // Convert string value to Date for the picker
                const dateValue = field.value ? new Date(field.value) : undefined;

                return (
                    <FormItem className={className}>
                        <FormLabel>{label}</FormLabel>
                        <FormControl>
                            <DatePicker
                                value={dateValue}
                                onChange={(date) => {
                                    // Store as ISO string (YYYY-MM-DD)
                                    field.onChange(date ? format(date, dateFormat) : '');
                                }}
                                placeholder={placeholder}
                                disabled={disabled}
                                minDate={minDate}
                                maxDate={maxDate}
                            />
                        </FormControl>
                        {description && <FormDescription>{description}</FormDescription>}
                        <FormMessage />
                    </FormItem>
                );
            }}
        />
    );
}
