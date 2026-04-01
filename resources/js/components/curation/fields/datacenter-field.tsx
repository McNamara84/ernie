import { Check, ChevronsUpDown } from 'lucide-react';
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
}

export function DatacenterField({ id, label, options, selected, onChange, className, required = false, hasError = false }: DatacenterFieldProps) {
    const [open, setOpen] = useState(false);

    const selectedNames = options.filter((o) => selected.includes(o.id)).map((o) => o.name);

    const toggleOption = (optionId: number) => {
        if (selected.includes(optionId)) {
            onChange(selected.filter((id) => id !== optionId));
        } else {
            onChange([...selected, optionId]);
        }
    };

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <Label htmlFor={id}>
                {label}
                {required && <span className="text-destructive ml-1">*</span>}
            </Label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-required={required}
                        aria-invalid={hasError}
                        className={cn('h-auto min-h-9 w-full justify-between font-normal', hasError && 'border-destructive')}
                        data-testid="datacenter-select"
                    >
                        <span className="flex flex-wrap gap-1">
                            {selectedNames.length > 0 ? (
                                selectedNames.map((name) => (
                                    <Badge key={name} variant="secondary" className="text-xs">
                                        {name}
                                    </Badge>
                                ))
                            ) : (
                                <span className="text-muted-foreground">Select datacenters...</span>
                            )}
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
            {hasError && selected.length === 0 && <p className="text-destructive text-sm">At least one datacenter is required.</p>}
        </div>
    );
}
