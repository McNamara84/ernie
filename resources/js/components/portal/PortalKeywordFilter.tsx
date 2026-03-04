import { Check, ChevronsUpDown, Search, Tag, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { KeywordSuggestion } from '@/types/portal';

interface PortalKeywordFilterProps {
    /** Available keyword suggestions from the server */
    suggestions: KeywordSuggestion[];
    /** Currently selected keywords */
    selectedKeywords: string[];
    /** Callback when keywords change */
    onKeywordsChange: (keywords: string[]) => void;
}

/** Scheme display labels for keyword groups */
const SCHEME_LABELS: Record<string, string> = {
    '': 'Free Keywords',
    'Science Keywords': 'GCMD Science Keywords',
    Platforms: 'GCMD Platforms',
    Instruments: 'GCMD Instruments',
    'EPOS MSL vocabulary': 'MSL Vocabularies',
};

/**
 * Get user-friendly label for a keyword scheme.
 */
function getSchemeLabel(scheme: string | null): string {
    return SCHEME_LABELS[scheme ?? ''] ?? (scheme || 'Free Keywords');
}

/**
 * Portal Keyword Filter
 *
 * Combobox-based multi-select filter for keywords in the portal sidebar.
 * Groups suggestions by scheme (Free Keywords, GCMD, MSL, etc.) and
 * displays selected keywords as removable chips.
 */
export function PortalKeywordFilter({ suggestions, selectedKeywords, onKeywordsChange }: PortalKeywordFilterProps) {
    const [open, setOpen] = useState(false);

    // Group suggestions by scheme for display.
    // Deduplicate by value first: since the backend filters by value only,
    // showing the same keyword in multiple scheme groups would cause
    // misleading checked-state. Keep the entry with the highest count.
    const groupedSuggestions = useMemo(() => {
        const bestByValue = new Map<string, KeywordSuggestion>();
        for (const suggestion of suggestions) {
            const existing = bestByValue.get(suggestion.value);
            if (!existing || suggestion.count > existing.count) {
                bestByValue.set(suggestion.value, suggestion);
            }
        }

        const groups = new Map<string, KeywordSuggestion[]>();

        for (const suggestion of bestByValue.values()) {
            const key = suggestion.scheme ?? '';
            const existing = groups.get(key) ?? [];
            existing.push(suggestion);
            groups.set(key, existing);
        }

        // Sort groups: Free Keywords first, then alphabetically
        return Array.from(groups.entries()).sort(([a], [b]) => {
            if (a === '') return -1;
            if (b === '') return 1;
            return a.localeCompare(b);
        });
    }, [suggestions]);

    const handleSelect = useCallback(
        (keyword: string) => {
            const newKeywords = selectedKeywords.includes(keyword)
                ? selectedKeywords.filter((k) => k !== keyword)
                : [...selectedKeywords, keyword];
            onKeywordsChange(newKeywords);
        },
        [selectedKeywords, onKeywordsChange],
    );

    const handleRemove = useCallback(
        (keyword: string) => {
            onKeywordsChange(selectedKeywords.filter((k) => k !== keyword));
        },
        [selectedKeywords, onKeywordsChange],
    );

    return (
        <div className="space-y-2">
            <Label className="text-sm font-medium">
                <span className="flex items-center gap-1.5">
                    <Tag className="h-3.5 w-3.5" />
                    Keywords
                </span>
            </Label>

            {/* Selected keywords as removable chips */}
            {selectedKeywords.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {selectedKeywords.map((keyword) => (
                        <Badge key={keyword} variant="secondary" className="gap-1 pr-1 text-xs">
                            {keyword}
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-4 w-4 p-0 hover:bg-transparent"
                                onClick={() => handleRemove(keyword)}
                                aria-label={`Remove keyword "${keyword}"`}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ))}
                </div>
            )}

            {/* Combobox dropdown */}
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        className="w-full justify-between text-sm font-normal"
                    >
                        <span className="flex items-center gap-2 text-muted-foreground">
                            <Search className="h-3.5 w-3.5" />
                            {selectedKeywords.length > 0
                                ? `${selectedKeywords.length} keyword${selectedKeywords.length > 1 ? 's' : ''} selected`
                                : 'Search keywords...'}
                        </span>
                        <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
                    <Command
                        filter={(value, search) => {
                            // The value is "scheme-keyword". Only match against the keyword part
                            // so that typing a scheme name (e.g. "msl") doesn't match items.
                            const separatorIndex = value.indexOf('-');
                            const keyword = separatorIndex >= 0 ? value.slice(separatorIndex + 1) : value;
                            return keyword.toLowerCase().includes(search.toLowerCase()) ? 1 : 0;
                        }}
                    >
                        <CommandInput placeholder="Type to filter keywords..." />
                        <CommandList className="max-h-64">
                            <CommandEmpty>No matching keywords found.</CommandEmpty>
                            {groupedSuggestions.map(([scheme, items]) => (
                                <CommandGroup key={scheme} heading={getSchemeLabel(scheme)}>
                                    {items.map((suggestion) => {
                                        const isSelected = selectedKeywords.includes(suggestion.value);
                                        return (
                                            <CommandItem
                                                key={`${scheme}-${suggestion.value}`}
                                                value={`${suggestion.scheme ?? ''}-${suggestion.value}`}
                                                onSelect={() => handleSelect(suggestion.value)}
                                            >
                                                <Check
                                                    className={cn('mr-2 h-4 w-4', isSelected ? 'opacity-100' : 'opacity-0')}
                                                />
                                                <span className="flex-1 truncate">{suggestion.value}</span>
                                                <span className="ml-2 text-xs text-muted-foreground">({suggestion.count})</span>
                                            </CommandItem>
                                        );
                                    })}
                                </CommandGroup>
                            ))}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>

            <p className="text-xs text-muted-foreground">Filter by free keywords, GCMD or MSL vocabularies</p>
        </div>
    );
}
