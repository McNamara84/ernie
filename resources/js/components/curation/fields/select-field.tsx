import { Combobox } from '@/components/ui/combobox';
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

import { EditorField, type EditorFieldProps } from './editor-field';

interface Option {
    value: string;
    label: string;
    keywords?: string[];
}

interface SelectFieldProps extends EditorFieldProps {
    value: string;
    onValueChange: (value: string) => void;
    options: Option[];
    placeholder?: string;
    clearable?: boolean;
    clearLabel?: string;
    searchable?: boolean;
    searchPlaceholder?: string;
    emptyMessage?: string;
    triggerClassName?: string;
    contentClassName?: string;
    contentPosition?: 'item-aligned' | 'popper';
    'data-testid'?: string;
    onValidationBlur?: () => void;
}

export function SelectField({
    id,
    value,
    onValueChange,
    options,
    placeholder = 'Select',
    required = false,
    clearable = false,
    clearLabel = 'Clear selection',
    searchable = false,
    searchPlaceholder = 'Search...',
    emptyMessage = 'No results found.',
    triggerClassName,
    contentClassName,
    contentPosition,
    'data-testid': dataTestId,
    onValidationBlur,
    ...fieldProps
}: SelectFieldProps) {
    const clearValue = `__clear-${id}__`;
    const handleValueChange = (newValue: string) => {
        onValueChange(newValue === clearValue ? '' : newValue);
        // Selects validate after the parent has received the new value.
        if (onValidationBlur) setTimeout(onValidationBlur, 0);
    };

    return (
        <EditorField {...fieldProps} id={id} required={required}>
            {(attributes) =>
                searchable ? (
                    <Combobox
                        id={id}
                        value={value || undefined}
                        onChange={(newValue) => handleValueChange(newValue ?? '')}
                        options={options.map((option) => ({
                            ...option,
                            keywords: [option.label, ...(option.keywords ?? [])],
                        }))}
                        placeholder={placeholder}
                        searchPlaceholder={searchPlaceholder}
                        emptyMessage={emptyMessage}
                        clearable={clearable}
                        clearLabel={clearLabel}
                        required={required}
                        error={attributes['aria-invalid'] === true}
                        ariaLabel={attributes['aria-label']}
                        ariaLabelledBy={attributes['aria-labelledby']}
                        ariaDescribedBy={attributes['aria-describedby']}
                        testId={dataTestId}
                        className={triggerClassName}
                        popoverWidth={cn('w-(--radix-popover-trigger-width)', contentClassName)}
                    />
                ) : (
                    <Select value={value} onValueChange={handleValueChange} required={required}>
                        <SelectTrigger
                            id={id}
                            aria-required={required || undefined}
                            className={triggerClassName}
                            data-testid={dataTestId}
                            {...attributes}
                        >
                            <SelectValue placeholder={placeholder} />
                        </SelectTrigger>
                        <SelectContent className={contentClassName} position={contentPosition}>
                            {clearable && value && (
                                <>
                                    <SelectItem value={clearValue}>{clearLabel}</SelectItem>
                                    <SelectSeparator />
                                </>
                            )}
                            {options.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )
            }
        </EditorField>
    );
}
