'use client';

import { Check, ChevronsUpDown, X } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface AsyncComboboxOption<T = unknown> {
    value: string;
    label: string;
    disabled?: boolean;
    /** Original data from the search */
    data?: T;
}

export interface AsyncComboboxProps<T = unknown> {
    /** Async search function */
    onSearch: (query: string) => Promise<AsyncComboboxOption<T>[]>;
    /** Selected value (single select) */
    value?: string;
    /** Selected values (multi select) */
    values?: string[];
    /** Selected option data (for display when value is set but options haven't loaded) */
    selectedOption?: AsyncComboboxOption<T>;
    /** Selected options data (for multi-select) */
    selectedOptions?: AsyncComboboxOption<T>[];
    /** Callback for single select */
    onChange?: (value: string | undefined, option: AsyncComboboxOption<T> | undefined) => void;
    /** Callback for multi select */
    onValuesChange?: (values: string[], options: AsyncComboboxOption<T>[]) => void;
    /** Enable multi-select mode */
    multiple?: boolean;
    /** Placeholder text */
    placeholder?: string;
    /** Search placeholder */
    searchPlaceholder?: string;
    /** Empty state message */
    emptyMessage?: string;
    /** Loading message */
    loadingMessage?: string;
    /** Minimum characters before search triggers */
    minSearchLength?: number;
    /** Debounce delay in ms */
    debounceMs?: number;
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
    renderOption?: (option: AsyncComboboxOption<T>) => React.ReactNode;
    /** Custom render for selected value display */
    renderValue?: (option: AsyncComboboxOption<T>) => React.ReactNode;
    /** Maximum items to show in multi-select badge display */
    maxDisplayItems?: number;
}

/**
 * AsyncCombobox Component
 *
 * A searchable dropdown with async data loading, built on shadcn/ui Command.
 * Supports debounced search, loading states, and both single/multi-select modes.
 *
 * @example Single select with async search
 * ```tsx
 * const [value, setValue] = useState<string>();
 * const [selectedUser, setSelectedUser] = useState<User>();
 *
 * const searchUsers = async (query: string) => {
 *     const response = await fetch(`/api/users?q=${query}`);
 *     const users = await response.json();
 *     return users.map(u => ({
 *         value: u.id,
 *         label: u.name,
 *         data: u,
 *     }));
 * };
 *
 * <AsyncCombobox
 *     onSearch={searchUsers}
 *     value={value}
 *     selectedOption={selectedUser ? { value: selectedUser.id, label: selectedUser.name } : undefined}
 *     onChange={(val, opt) => {
 *         setValue(val);
 *         setSelectedUser(opt?.data);
 *     }}
 *     placeholder="Search users..."
 * />
 * ```
 */
export function AsyncCombobox<T = unknown>({
    onSearch,
    value,
    values = [],
    selectedOption,
    selectedOptions = [],
    onChange,
    onValuesChange,
    multiple = false,
    placeholder = 'Search...',
    searchPlaceholder = 'Type to search...',
    emptyMessage = 'No results found.',
    loadingMessage = 'Searching...',
    minSearchLength = 1,
    debounceMs = 300,
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
}: AsyncComboboxProps<T>) {
    const [open, setOpen] = React.useState(false);
    const [searchQuery, setSearchQuery] = React.useState('');
    const [options, setOptions] = React.useState<AsyncComboboxOption<T>[]>([]);
    const [isLoading, setIsLoading] = React.useState(false);
    const debounceTimerRef = React.useRef<NodeJS.Timeout | null>(null);

    // Perform search with debounce
    React.useEffect(() => {
        if (debounceTimerRef.current) {
            clearTimeout(debounceTimerRef.current);
        }

        if (searchQuery.length < minSearchLength) {
            setOptions([]);
            setIsLoading(false);
            return;
        }

        setIsLoading(true);

        debounceTimerRef.current = setTimeout(async () => {
            try {
                const results = await onSearch(searchQuery);
                setOptions(results);
            } catch (error) {
                console.error('AsyncCombobox search error:', error);
                setOptions([]);
            } finally {
                setIsLoading(false);
            }
        }, debounceMs);

        return () => {
            if (debounceTimerRef.current) {
                clearTimeout(debounceTimerRef.current);
            }
        };
    }, [searchQuery, onSearch, minSearchLength, debounceMs]);

    // Merge selected options with search results for display
    const allOptions = React.useMemo(() => {
        const optionMap = new Map<string, AsyncComboboxOption<T>>();

        // Add selected options first
        if (multiple) {
            selectedOptions.forEach((opt) => optionMap.set(opt.value, opt));
        } else if (selectedOption) {
            optionMap.set(selectedOption.value, selectedOption);
        }

        // Add search results
        options.forEach((opt) => optionMap.set(opt.value, opt));

        return Array.from(optionMap.values());
    }, [options, selectedOption, selectedOptions, multiple]);

    const handleSelect = (selectedValue: string) => {
        const option = allOptions.find((opt) => opt.value === selectedValue);

        if (multiple) {
            let newValues: string[];
            let newOptions: AsyncComboboxOption<T>[];

            if (values.includes(selectedValue)) {
                newValues = values.filter((v) => v !== selectedValue);
                newOptions = selectedOptions.filter((opt) => opt.value !== selectedValue);
            } else {
                newValues = [...values, selectedValue];
                newOptions = option ? [...selectedOptions, option] : selectedOptions;
            }

            onValuesChange?.(newValues, newOptions);
        } else {
            if (selectedValue === value) {
                onChange?.(undefined, undefined);
            } else {
                onChange?.(selectedValue, option);
            }
            setOpen(false);
        }
    };

    const handleClear = (e: React.MouseEvent) => {
        e.stopPropagation();
        if (multiple) {
            onValuesChange?.([], []);
        } else {
            onChange?.(undefined, undefined);
        }
    };

    const handleRemoveValue = (e: React.MouseEvent, valueToRemove: string) => {
        e.stopPropagation();
        const newValues = values.filter((v) => v !== valueToRemove);
        const newOptions = selectedOptions.filter((opt) => opt.value !== valueToRemove);
        onValuesChange?.(newValues, newOptions);
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
                            <button
                                type="button"
                                className="ml-1 rounded-full outline-none ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                onClick={(e) => handleRemoveValue(e, opt.value)}
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
                <Command shouldFilter={false}>
                    <CommandInput placeholder={searchPlaceholder} value={searchQuery} onValueChange={setSearchQuery} />
                    <CommandList>
                        {isLoading ? (
                            <div className="flex items-center justify-center py-6">
                                <Spinner size="sm" className="mr-2" />
                                <span className="text-sm text-muted-foreground">{loadingMessage}</span>
                            </div>
                        ) : searchQuery.length < minSearchLength ? (
                            <div className="py-6 text-center text-sm text-muted-foreground">
                                Type at least {minSearchLength} character{minSearchLength > 1 ? 's' : ''} to search
                            </div>
                        ) : options.length === 0 ? (
                            <CommandEmpty>{emptyMessage}</CommandEmpty>
                        ) : (
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
                        )}
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
