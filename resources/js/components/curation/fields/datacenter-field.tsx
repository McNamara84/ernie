import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
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
    selected: number[];
    onChange: (selected: number[]) => void;
    className?: string;
    required?: boolean;
    hasError?: boolean;
    errorMessage?: string;
}

export function DatacenterField({ id, label, options, selected, onChange, className, required = false, hasError = false, errorMessage }: DatacenterFieldProps) {
    const [open, setOpen] = useState(false);

    const selectedOptions = options.filter((o) => selected.includes(o.id));

    const toggleOption = (optionId: number) => {
        if (selected.includes(optionId)) {
            onChange(selected.filter((id) => id !== optionId));
        } else {
            onChange([...selected, optionId]);
        }
    };

    return (
        <div className={cn('flex min-w-0 flex-col gap-2', className)}>
            <Label htmlFor={id}>
                {label}
                {required && <span className="ml-1 font-bold text-destructive">*</span>}
            </Label>
            {selectedOptions.length > 0 && (
                <div className="flex min-w-0 flex-wrap gap-1">
                    {selectedOptions.map((option) => (
                        <Badge key={option.id} variant="secondary" className="h-auto max-w-full items-start gap-1 whitespace-normal pr-1 text-left text-xs wrap-break-word">
                            {option.name}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                aria-label={`Remove datacenter "${option.name}"`}
                                className="size-4 shrink-0 rounded-sm p-0 text-muted-foreground hover:text-foreground"
                                onClick={() => onChange(selected.filter((sid) => sid !== option.id))}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ))}
                </div>
            )}
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
                        <span className="min-w-0 flex-1 text-left text-muted-foreground">
                            {selectedOptions.length > 0
                                ? `${selectedOptions.length} datacenter${selectedOptions.length > 1 ? 's' : ''} selected`
                                : 'Select datacenters...'}
                        </span>
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                    <Command>
                        <CommandInput placeholder="Search datacenters..." />
                        <CommandList>
                            <CommandEmpty>No datacenter found.</CommandEmpty>
                            <CommandGroup>
                                {options.map((option) => (
                                    <CommandItem key={option.id} value={option.name} onSelect={() => toggleOption(option.id)}>
                                        <Check className={cn('mr-2 h-4 w-4', selected.includes(option.id) ? 'opacity-100' : 'opacity-0')} />
                                        {option.name}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {hasError && errorMessage && <p className="text-destructive text-sm">{errorMessage}</p>}
        </div>
    );
}
