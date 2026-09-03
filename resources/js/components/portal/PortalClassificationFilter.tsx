import { Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { PortalClassificationFacetGroup } from '@/types/portal';

interface PortalClassificationFilterProps {
    groups: PortalClassificationFacetGroup[];
    selectedValues: string[];
    onSelectionChange: (values: string[]) => void;
}

export function PortalClassificationFilter({ groups, selectedValues, onSelectionChange }: PortalClassificationFilterProps) {
    const [search, setSearch] = useState('');
    const selected = useMemo(() => new Set(selectedValues), [selectedValues]);
    const labels = useMemo(() => new Map(groups.flatMap((group) => group.options.map((option) => [option.value, option.label] as const))), [groups]);
    const visibleGroups = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();
        if (needle === '') return groups;

        return groups
            .map((group) => ({
                ...group,
                options: group.options.filter((option) => option.label.toLocaleLowerCase().includes(needle)),
            }))
            .filter((group) => group.options.length > 0);
    }, [groups, search]);

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

            {groups.length > 0 && (
                <div className="relative">
                    <Search className="pointer-events-none absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search classifications..."
                        aria-label="Search classifications"
                        className="h-9 pl-8"
                    />
                </div>
            )}

            {groups.length === 0 ? (
                <p className="text-sm text-muted-foreground">No classifications available.</p>
            ) : visibleGroups.length === 0 ? (
                <p className="text-sm text-muted-foreground">No matching classifications.</p>
            ) : (
                <div className="space-y-3" aria-label="Classifications">
                    {visibleGroups.map((group) => (
                        <div key={group.type}>
                            <p className="mb-1 px-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">{group.label}</p>
                            <div role="group" aria-label={`${group.label} classifications`} className="space-y-1">
                                {group.options.map((option) => (
                                    <label
                                        key={option.value}
                                        className="flex min-h-8 cursor-pointer items-center gap-2 rounded-md px-2 py-1 hover:bg-muted/60"
                                    >
                                        <Checkbox
                                            checked={selected.has(option.value)}
                                            onCheckedChange={() => toggle(option.value)}
                                            aria-label={`Select ${option.label}`}
                                        />
                                        <span className="min-w-0 flex-1 truncate text-sm" title={option.label}>
                                            {option.label}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">{option.count.toLocaleString('en-US')}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <p className="text-xs text-muted-foreground">Every selected classification must be present.</p>
        </div>
    );
}
