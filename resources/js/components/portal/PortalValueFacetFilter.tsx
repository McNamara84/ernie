import { Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
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
    wrapLabels?: boolean;
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
    wrapLabels = false,
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
        <div className={cn('space-y-3', wrapLabels && 'max-w-full min-w-0')}>
            {selectedValues.length > 0 && (
                <div className={cn('flex flex-wrap gap-1.5', wrapLabels && 'max-w-full min-w-0')}>
                    {selectedValues.map((value) => (
                        <Badge
                            key={value}
                            variant="secondary"
                            className={cn('gap-1 pr-1 text-xs', wrapLabels && 'max-w-full min-w-0 justify-between whitespace-normal')}
                        >
                            {wrapLabels ? (
                                <span className="min-w-0 flex-1 text-left [overflow-wrap:anywhere]">{labels.get(value) ?? value}</span>
                            ) : (
                                (labels.get(value) ?? value)
                            )}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className={cn('h-4 w-4 p-0 hover:bg-transparent', wrapLabels && 'shrink-0')}
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
                <div role="group" aria-label={ariaLabel} className={cn('space-y-1', wrapLabels && 'max-w-full min-w-0')}>
                    {visibleOptions.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex min-h-8 cursor-pointer items-center gap-2 rounded-md px-2 py-1 hover:bg-muted/60',
                                wrapLabels && 'max-w-full min-w-0 items-start',
                            )}
                        >
                            <Checkbox
                                checked={selected.has(option.value)}
                                onCheckedChange={() => toggle(option.value)}
                                aria-label={`Select ${option.label}`}
                                className={wrapLabels ? 'mt-0.5' : undefined}
                            />
                            <span
                                className={cn(
                                    'min-w-0 flex-1',
                                    wrapLabels ? 'text-xs [overflow-wrap:anywhere] whitespace-normal' : 'truncate text-sm',
                                )}
                            >
                                {option.label}
                            </span>
                            <span className={cn('text-xs text-muted-foreground tabular-nums', wrapLabels && 'mt-0.5 shrink-0')}>
                                {option.count.toLocaleString('en-US')}
                            </span>
                        </label>
                    ))}
                </div>
            )}

            <p className="text-xs text-muted-foreground">{helperText}</p>
        </div>
    );
}
