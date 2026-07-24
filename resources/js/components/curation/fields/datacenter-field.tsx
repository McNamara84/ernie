import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface DatacenterOption {
    id: number;
    name: string;
}

interface DatacenterFieldProps {
    id: string;
    label: string;
    options: DatacenterOption[];
    selected: number | null;
    onChange: (selected: number | null) => void;
    className?: string;
    required?: boolean;
    hasError?: boolean;
    errorMessage?: string;
}

export function DatacenterField({
    id,
    label,
    options,
    selected,
    onChange,
    className,
    required = false,
    hasError = false,
    errorMessage,
}: DatacenterFieldProps) {
    const [open, setOpen] = useState(false);

    const selectedOption = options.find((option) => option.id === selected);

    return (
        <div className={cn('flex min-w-0 flex-col gap-2', className)}>
            <Label htmlFor={id}>
                {label}
                {required && <span className="ml-1 font-bold text-destructive">*</span>}
            </Label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-required={required}
                        aria-invalid={hasError}
                        className={cn('h-auto min-h-9 w-full min-w-0 justify-between font-normal', hasError && 'border-destructive')}
                        data-testid="datacenter-select"
                    >
                        <span className="min-w-0 flex-1 text-left wrap-break-word whitespace-normal text-muted-foreground">
                            {selectedOption?.name ?? 'Select a datacenter...'}
                        </span>
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                    <Command>
                        <CommandInput placeholder="Search datacenters..." />
                        <CommandList>
                            <CommandEmpty>No datacenter found.</CommandEmpty>
                            {selected !== null && (
                                <>
                                    <CommandGroup>
                                        <CommandItem
                                            value="Clear selection"
                                            onSelect={() => {
                                                onChange(null);
                                                setOpen(false);
                                            }}
                                        >
                                            <X className="mr-2 h-4 w-4" />
                                            Clear selection
                                        </CommandItem>
                                    </CommandGroup>
                                    <CommandSeparator />
                                </>
                            )}
                            <CommandGroup>
                                {options.map((option) => (
                                    <CommandItem
                                        key={option.id}
                                        value={option.name}
                                        onSelect={() => {
                                            onChange(option.id);
                                            setOpen(false);
                                        }}
                                    >
                                        <Check className={cn('mr-2 h-4 w-4', selected === option.id ? 'opacity-100' : 'opacity-0')} />
                                        {option.name}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {hasError && errorMessage && <p className="text-sm text-destructive">{errorMessage}</p>}
        </div>
    );
}
