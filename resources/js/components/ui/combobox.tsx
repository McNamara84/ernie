'use client';

import { Check, ChevronsUpDown, X } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface ComboboxOption {
    value: string;
    label: string;
    disabled?: boolean;
    /** Additional data to pass through */
    data?: Record<string, unknown>;
}

export interface ComboboxProps {
    /** Available options */
    options: ComboboxOption[];
    /** Selected value (single select) */
    value?: string;
    /** Selected values (multi select) */
    values?: string[];
    /** Callback for single select */
    onChange?: (value: string | undefined) => void;
    /** Callback for multi select */
    onValuesChange?: (values: string[]) => void;
    /** Enable multi-select mode */
    multiple?: boolean;
    /** Placeholder text */
    placeholder?: string;
    /** Search placeholder */
    searchPlaceholder?: string;
    /** Empty state message */
    emptyMessage?: string;
    /** Disable the combobox */
    disabled?: boolean;
    /** Show clear button */
    clearable?: boolean;
    /** Additional class names */
    className?: string;
    /** Popover width */
    popoverWidth?: string;
    /** ID for form association */
    id?: string;
    /** Name for form association */
    name?: string;
    /** Required field */
    required?: boolean;
    /** Error state */
    error?: boolean;
    /** Custom render for options */
    renderOption?: (option: ComboboxOption) => React.ReactNode;
    /** Custom render for selected value display */
    renderValue?: (option: ComboboxOption) => React.ReactNode;
    /** Maximum items to show in multi-select badge display */
    maxDisplayItems?: number;
}

/**
 * Combobox Component
 *
 * A searchable dropdown built on shadcn/ui Command and Popover components.
 * Supports both single and multi-select modes.
 *
 * @example Single select
 * ```tsx
 * const [value, setValue] = useState<string>();
 *
 * <Combobox
 *     options={[
 *         { value: 'apple', label: 'Apple' },
 *         { value: 'banana', label: 'Banana' },
 *     ]}
 *     value={value}
 *     onChange={setValue}
 *     placeholder="Select a fruit"
 * />
 * ```
 *
 * @example Multi select
 * ```tsx
 * const [values, setValues] = useState<string[]>([]);
 *
 * <Combobox
 *     multiple
 *     options={fruits}
 *     values={values}
 *     onValuesChange={setValues}
 *     placeholder="Select fruits"
 * />
 * ```
 */
export function Combobox({
    options,
    value,
    values = [],
    onChange,
    onValuesChange,
    multiple = false,
    placeholder = 'Select...',
    searchPlaceholder = 'Search...',
    emptyMessage = 'No results found.',
    disabled = false,
    clearable = true,
    className,
    popoverWidth = 'w-full',
    id,
    name,
    required,
    error,
    renderOption,
    renderValue,
    maxDisplayItems = 3,
}: ComboboxProps) {
    const [open, setOpen] = React.useState(false);

    // Get selected option(s)
    const selectedOption = options.find((opt) => opt.value === value);
    const selectedOptions = options.filter((opt) => values.includes(opt.value));

    const handleSelect = (selectedValue: string) => {
        if (multiple) {
            const newValues = values.includes(selectedValue) ? values.filter((v) => v !== selectedValue) : [...values, selectedValue];
            onValuesChange?.(newValues);
        } else {
            onChange?.(selectedValue === value ? undefined : selectedValue);
            setOpen(false);
        }
    };

    const handleClear = (e: React.MouseEvent) => {
        e.stopPropagation();
        if (multiple) {
            onValuesChange?.([]);
        } else {
            onChange?.(undefined);
        }
    };

    const handleRemoveValue = (e: React.MouseEvent, valueToRemove: string) => {
        e.stopPropagation();
        onValuesChange?.(values.filter((v) => v !== valueToRemove));
    };

    const renderTriggerContent = () => {
        if (multiple) {
            if (selectedOptions.length === 0) {
                return <span className="text-muted-foreground">{placeholder}</span>;
            }

            const displayItems = selectedOptions.slice(0, maxDisplayItems);
            const remaining = selectedOptions.length - maxDisplayItems;

            return (
                <div className="flex flex-wrap gap-1">
                    {displayItems.map((opt) => (
                        <Badge key={opt.value} variant="secondary" className="mr-1">
                            {renderValue ? renderValue(opt) : opt.label}
                            {/* Native button used here intentionally - Button component would break Badge layout */}
                            <button
                                type="button"
                                className="ml-1 rounded-full outline-none ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                onClick={(e) => handleRemoveValue(e, opt.value)}
                                aria-label={`Remove ${opt.label}`}
                            >
                                <X className="h-3 w-3" />
                            </button>
                        </Badge>
                    ))}
                    {remaining > 0 && (
                        <Badge variant="secondary" className="mr-1">
                            +{remaining} more
                        </Badge>
                    )}
                </div>
            );
        }

        if (selectedOption) {
            return renderValue ? renderValue(selectedOption) : selectedOption.label;
        }

        return <span className="text-muted-foreground">{placeholder}</span>;
    };

    const showClearButton = clearable && (multiple ? values.length > 0 : !!value);

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
                        'w-full justify-between',
                        multiple && selectedOptions.length > 0 && 'h-auto min-h-9 py-2',
                        error && 'border-destructive',
                        className,
                    )}
                >
                    <span className="flex-1 text-left">{renderTriggerContent()}</span>
                    <div className="flex items-center gap-1">
                        {showClearButton && (
                            <X className="h-4 w-4 shrink-0 opacity-50 hover:opacity-100" onClick={handleClear} aria-label="Clear selection" />
                        )}
                        <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
                    </div>
                </Button>
            </PopoverTrigger>
            <PopoverContent className={cn('p-0', popoverWidth)}>
                <Command>
                    <CommandInput placeholder={searchPlaceholder} />
                    <CommandList>
                        <CommandEmpty>{emptyMessage}</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => {
                                const isSelected = multiple ? values.includes(option.value) : value === option.value;

                                return (
                                    <CommandItem
                                        key={option.value}
                                        value={option.value}
                                        disabled={option.disabled}
                                        onSelect={() => handleSelect(option.value)}
                                    >
                                        <Check className={cn('mr-2 h-4 w-4', isSelected ? 'opacity-100' : 'opacity-0')} />
                                        {renderOption ? renderOption(option) : option.label}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
            {/* Hidden input(s) for form submission */}
            {name &&
                (multiple ? (
                    values.map((v, i) => <input key={i} type="hidden" name={`${name}[]`} value={v} />)
                ) : (
                    <input type="hidden" name={name} value={value ?? ''} />
                ))}
        </Popover>
    );
}
