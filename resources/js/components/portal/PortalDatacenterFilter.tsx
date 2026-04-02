import { ChevronsUpDown, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { DatacenterFacet } from '@/types/portal';

interface PortalDatacenterFilterProps {
    facets: DatacenterFacet[];
    selectedNames: string[];
    onSelectionChange: (names: string[]) => void;
}

export function PortalDatacenterFilter({ facets, selectedNames, onSelectionChange }: PortalDatacenterFilterProps) {
    const [open, setOpen] = useState(false);

    const selectedSet = useMemo(() => new Set(selectedNames), [selectedNames]);

    const toggleName = useCallback(
        (name: string) => {
            const next = selectedSet.has(name) ? selectedNames.filter((n) => n !== name) : [...selectedNames, name];
            onSelectionChange(next);
        },
        [selectedNames, selectedSet, onSelectionChange],
    );

    const clearSelection = useCallback(() => {
        onSelectionChange([]);
    }, [onSelectionChange]);

    const selectedCount = selectedNames.length;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline" size="sm" className="h-9 w-full justify-between font-normal">
                    <span className="truncate">{selectedCount === 0 ? 'All Datacenters' : `${selectedCount} selected`}</span>
                    <div className="flex items-center gap-1">
                        {selectedCount > 0 && (
                            <Badge variant="secondary" className="h-5 min-w-5 justify-center px-1 text-xs">
                                {selectedCount}
                            </Badge>
                        )}
                        <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
                    </div>
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0" align="start">
                <Command>
                    <CommandInput placeholder="Search datacenters..." />
                    <CommandList>
                        <CommandEmpty>No datacenters found.</CommandEmpty>
                        <CommandGroup>
                            {facets.map((facet) => {
                                const isSelected = selectedSet.has(facet.name);
                                return (
                                    <CommandItem key={facet.name} value={facet.name} onSelect={() => toggleName(facet.name)}>
                                        <Checkbox checked={isSelected} className="pointer-events-none" />
                                        <span className="flex-1 truncate">{facet.name}</span>
                                        <span className={cn('ml-auto text-xs tabular-nums', isSelected ? 'text-foreground' : 'text-muted-foreground')}>
                                            {facet.count}
                                        </span>
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                    {selectedCount > 0 && (
                        <div className="border-t p-1">
                            <Button variant="ghost" size="sm" className="w-full justify-center text-xs" onClick={clearSelection}>
                                <X className="mr-1 h-3 w-3" />
                                Clear filter
                            </Button>
                        </div>
                    )}
                </Command>
            </PopoverContent>
        </Popover>
    );
}
