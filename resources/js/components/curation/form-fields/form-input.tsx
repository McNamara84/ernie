/**
 * Form Input Component
 *
 * A form-integrated input field with label, description, and error handling.
 */

import type { Control, FieldPath, FieldValues } from 'react-hook-form';

import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';

export interface FormInputProps<TFieldValues extends FieldValues = FieldValues> {
    control: Control<TFieldValues>;
    name: FieldPath<TFieldValues>;
    label: string;
    description?: string;
    placeholder?: string;
    type?: 'text' | 'email' | 'password' | 'number' | 'url' | 'tel';
    disabled?: boolean;
    className?: string;
    autoComplete?: string;
}

export function FormInput<TFieldValues extends FieldValues = FieldValues>({
    control,
    name,
    label,
    description,
    placeholder,
    type = 'text',
    disabled = false,
    className,
    autoComplete,
}: FormInputProps<TFieldValues>) {
    return (
        <FormField
            control={control}
            name={name}
            render={({ field }) => (
                <FormItem className={className}>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        <Input {...field} type={type} placeholder={placeholder} disabled={disabled} autoComplete={autoComplete} />
                    </FormControl>
                    {description && <FormDescription>{description}</FormDescription>}
                    <FormMessage />
                </FormItem>
            )}
        />
    );
}
