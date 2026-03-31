import { ChevronsUpDown, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { ResourceTypeFacet } from '@/types/portal';

interface PortalResourceTypeFilterProps {
    facets: ResourceTypeFacet[];
    selectedSlugs: string[];
    onSelectionChange: (slugs: string[]) => void;
}

export function PortalResourceTypeFilter({ facets, selectedSlugs, onSelectionChange }: PortalResourceTypeFilterProps) {
    const [open, setOpen] = useState(false);

    const selectedSet = useMemo(() => new Set(selectedSlugs), [selectedSlugs]);

    const toggleSlug = useCallback(
        (slug: string) => {
            const next = selectedSet.has(slug) ? selectedSlugs.filter((s) => s !== slug) : [...selectedSlugs, slug];
            onSelectionChange(next);
        },
        [selectedSlugs, selectedSet, onSelectionChange],
    );

    const clearSelection = useCallback(() => {
        onSelectionChange([]);
    }, [onSelectionChange]);

    const selectedCount = selectedSlugs.length;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline" size="sm" className="h-9 w-full justify-between font-normal">
                    <span className="truncate">
                        {selectedCount === 0 ? 'All Resource Types' : `${selectedCount} selected`}
                    </span>
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
                    <CommandInput placeholder="Search types..." />
                    <CommandList>
                        <CommandEmpty>No resource types found.</CommandEmpty>
                        <CommandGroup>
                            {facets.map((facet) => {
                                const isSelected = selectedSet.has(facet.slug);
                                return (
                                    <CommandItem key={facet.slug} value={facet.name} onSelect={() => toggleSlug(facet.slug)}>
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
                                Clear selection
                            </Button>
                        </div>
                    )}
                </Command>
            </PopoverContent>
        </Popover>
    );
}
