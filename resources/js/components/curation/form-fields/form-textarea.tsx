/**
 * Form Textarea Component
 *
 * A form-integrated textarea field with label, description, and error handling.
 */

import type { Control, FieldPath, FieldValues } from 'react-hook-form';

import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Textarea } from '@/components/ui/textarea';

export interface FormTextareaProps<TFieldValues extends FieldValues = FieldValues> {
    control: Control<TFieldValues>;
    name: FieldPath<TFieldValues>;
    label: string;
    description?: string;
    placeholder?: string;
    rows?: number;
    disabled?: boolean;
    className?: string;
}

export function FormTextarea<TFieldValues extends FieldValues = FieldValues>({
    control,
    name,
    label,
    description,
    placeholder,
    rows = 4,
    disabled = false,
    className,
}: FormTextareaProps<TFieldValues>) {
    return (
        <FormField
            control={control}
            name={name}
            render={({ field }) => (
                <FormItem className={className}>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        <Textarea {...field} placeholder={placeholder} rows={rows} disabled={disabled} />
                    </FormControl>
                    {description && <FormDescription>{description}</FormDescription>}
                    <FormMessage />
                </FormItem>
            )}
        />
    );
}
