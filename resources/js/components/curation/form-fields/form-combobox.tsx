/**
 * Form Combobox Component
 *
 * A form-integrated combobox/autocomplete field with label, description, and error handling.
 */

import type { Control, FieldPath, FieldValues } from 'react-hook-form';

import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';

export interface FormComboboxProps<TFieldValues extends FieldValues = FieldValues> {
    control: Control<TFieldValues>;
    name: FieldPath<TFieldValues>;
    label: string;
    description?: string;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyMessage?: string;
    options: ComboboxOption[];
    disabled?: boolean;
    className?: string;
}

export function FormCombobox<TFieldValues extends FieldValues = FieldValues>({
    control,
    name,
    label,
    description,
    placeholder = 'Select option',
    searchPlaceholder = 'Search...',
    emptyMessage = 'No results found.',
    options,
    disabled = false,
    className,
}: FormComboboxProps<TFieldValues>) {
    return (
        <FormField
            control={control}
            name={name}
            render={({ field }) => (
                <FormItem className={className}>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        <Combobox
                            options={options}
                            value={field.value}
                            onChange={field.onChange}
                            placeholder={placeholder}
                            searchPlaceholder={searchPlaceholder}
                            emptyMessage={emptyMessage}
                            disabled={disabled}
                        />
                    </FormControl>
                    {description && <FormDescription>{description}</FormDescription>}
                    <FormMessage />
                </FormItem>
            )}
        />
    );
}
