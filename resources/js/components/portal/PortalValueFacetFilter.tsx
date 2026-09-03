import { Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { PortalValueFacet } from '@/types/portal';

interface PortalValueFacetFilterProps {
    options: PortalValueFacet[];
    selectedValues: string[];
    onSelectionChange: (values: string[]) => void;
    ariaLabel: string;
    emptyMessage: string;
    helperText: string;
    searchable?: boolean;
    searchPlaceholder?: string;
}

export function PortalValueFacetFilter({
    options,
    selectedValues,
    onSelectionChange,
    ariaLabel,
    emptyMessage,
    helperText,
    searchable = false,
    searchPlaceholder = 'Search values...',
}: PortalValueFacetFilterProps) {
    const [search, setSearch] = useState('');
    const selected = useMemo(() => new Set(selectedValues), [selectedValues]);
    const labels = useMemo(() => new Map(options.map((option) => [option.value, option.label])), [options]);
    const visibleOptions = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();
        if (needle === '') return options;

        return options.filter((option) => option.label.toLocaleLowerCase().includes(needle));
    }, [options, search]);

    const toggle = (value: string) => {
        onSelectionChange(selected.has(value) ? selectedValues.filter((selectedValue) => selectedValue !== value) : [...selectedValues, value]);
    };

    return (
        <div className="space-y-3">
            {selectedValues.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {selectedValues.map((value) => (
                        <Badge key={value} variant="secondary" className="gap-1 pr-1 text-xs">
                            {labels.get(value) ?? value}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-4 w-4 p-0 hover:bg-transparent"
                                onClick={() => toggle(value)}
                                aria-label={`Remove ${labels.get(value) ?? value}`}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ))}
                </div>
            )}

            {searchable && options.length > 0 && (
                <div className="relative">
                    <Search className="pointer-events-none absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={searchPlaceholder}
                        aria-label={searchPlaceholder}
                        className="h-9 pl-8"
                    />
                </div>
            )}

            {options.length === 0 ? (
                <p className="text-sm text-muted-foreground">{emptyMessage}</p>
            ) : visibleOptions.length === 0 ? (
                <p className="text-sm text-muted-foreground">No matching values.</p>
            ) : (
                <div role="group" aria-label={ariaLabel} className="space-y-1">
                    {visibleOptions.map((option) => (
                        <label key={option.value} className="flex min-h-8 cursor-pointer items-center gap-2 rounded-md px-2 py-1 hover:bg-muted/60">
                            <Checkbox
                                checked={selected.has(option.value)}
                                onCheckedChange={() => toggle(option.value)}
                                aria-label={`Select ${option.label}`}
                            />
                            <span className="min-w-0 flex-1 truncate text-sm">{option.label}</span>
                            <span className="text-xs text-muted-foreground tabular-nums">{option.count.toLocaleString('en-US')}</span>
                        </label>
                    ))}
                </div>
            )}

            <p className="text-xs text-muted-foreground">{helperText}</p>
        </div>
    );
}
