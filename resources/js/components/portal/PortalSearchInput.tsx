import { LoaderCircle, Search, Tag, X } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePortalKeywordSuggestions } from '@/hooks/use-portal-keyword-suggestions';
import { cn } from '@/lib/utils';
import type { PortalBasePath } from '@/types/portal';

interface PortalSearchInputProps {
    basePath?: PortalBasePath;
    value: string;
    onValueChange: (value: string) => void;
    onSubmit: (query: string) => void;
    selectedKeywords: string[];
    onKeywordSelect: (keyword: string) => void;
    onKeywordsChange: (keywords: string[]) => void;
}

export function PortalSearchInput({
    basePath = '/doi-search',
    value,
    onValueChange,
    onSubmit,
    selectedKeywords,
    onKeywordSelect,
    onKeywordsChange,
}: PortalSearchInputProps) {
    const listboxId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const [canShowSuggestions, setCanShowSuggestions] = useState(false);
    const [highlightedKeyword, setHighlightedKeyword] = useState<string | null>(null);
    const { data: suggestions = [], isFetching, isError } = usePortalKeywordSuggestions(value, basePath);
    const visibleSuggestions = suggestions.filter((suggestion) => !selectedKeywords.includes(suggestion.value));
    const highlightedIndex = visibleSuggestions.findIndex((suggestion) => suggestion.value === highlightedKeyword);
    const showLoading = isFetching && suggestions.length === 0;
    const showError = isError && visibleSuggestions.length === 0;
    const showSuggestions = canShowSuggestions && value.trim().length >= 2 && (visibleSuggestions.length > 0 || showLoading || showError);

    useEffect(() => {
        setHighlightedKeyword(null);
    }, [value]);

    const selectKeyword = useCallback(
        (keyword: string) => {
            onKeywordSelect(keyword);
            onValueChange('');
            setHighlightedKeyword(null);
            inputRef.current?.focus();
        },
        [onKeywordSelect, onValueChange],
    );

    const removeKeyword = useCallback(
        (keyword: string) => onKeywordsChange(selectedKeywords.filter((selected) => selected !== keyword)),
        [onKeywordsChange, selectedKeywords],
    );

    const handleSubmit = useCallback(
        (event: React.FormEvent) => {
            event.preventDefault();
            const query = value.trim();

            onSubmit(query);
        },
        [onSubmit, value],
    );

    const handleKeyDown = useCallback(
        (event: React.KeyboardEvent<HTMLInputElement>) => {
            if (!showSuggestions) return;

            if (event.key === 'Escape') {
                event.preventDefault();
                setCanShowSuggestions(false);
                setHighlightedKeyword(null);
                return;
            }

            if (visibleSuggestions.length === 0) return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                const nextIndex = (highlightedIndex + 1) % visibleSuggestions.length;
                setHighlightedKeyword(visibleSuggestions[nextIndex].value);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                const previousIndex = highlightedIndex <= 0 ? visibleSuggestions.length - 1 : highlightedIndex - 1;
                setHighlightedKeyword(visibleSuggestions[previousIndex].value);
            } else if (event.key === 'Enter' && highlightedIndex >= 0) {
                event.preventDefault();
                selectKeyword(visibleSuggestions[highlightedIndex].value);
            }
        },
        [highlightedIndex, selectKeyword, showSuggestions, visibleSuggestions],
    );

    return (
        <div className="space-y-2">
            <label htmlFor="portal-search" className="text-sm font-medium">
                Search
            </label>
            <form onSubmit={handleSubmit} className="relative">
                <div
                    className={cn(
                        'flex min-h-10 flex-wrap items-center gap-1.5 rounded-md border border-input bg-background px-2 py-1 shadow-xs',
                        'focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                    )}
                >
                    {selectedKeywords.map((keyword) => (
                        <Badge key={keyword} variant="secondary" className="max-w-full gap-1 pr-1 text-xs">
                            <Tag className="h-3 w-3 shrink-0" />
                            <span className="truncate">{keyword}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                className="size-5 rounded-sm hover:bg-muted"
                                onClick={() => removeKeyword(keyword)}
                                aria-label={`Remove keyword "${keyword}"`}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ))}
                    <input
                        ref={inputRef}
                        id="portal-search"
                        type="text"
                        role="combobox"
                        aria-expanded={showSuggestions}
                        aria-controls={showSuggestions ? listboxId : undefined}
                        aria-activedescendant={showSuggestions && highlightedIndex >= 0 ? `${listboxId}-${highlightedIndex}` : undefined}
                        autoComplete="off"
                        placeholder={selectedKeywords.length > 0 ? 'Add text or keyword...' : 'Search text or keywords...'}
                        value={value}
                        onChange={(event) => {
                            onValueChange(event.target.value);
                            setCanShowSuggestions(true);
                        }}
                        onFocus={() => setCanShowSuggestions(true)}
                        onBlur={() => setCanShowSuggestions(false)}
                        onKeyDown={handleKeyDown}
                        className="h-8 min-w-28 flex-1 bg-transparent px-1 text-sm outline-none placeholder:text-muted-foreground"
                    />
                    {value && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7"
                            onClick={() => onValueChange('')}
                            aria-label="Clear search"
                        >
                            <X className="h-3.5 w-3.5" />
                        </Button>
                    )}
                    <Button type="submit" size="icon" className="h-8 w-8 shrink-0" aria-label="Search">
                        <Search className="h-4 w-4" />
                    </Button>
                </div>

                {showSuggestions && (
                    <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md">
                        <ul id={listboxId} role="listbox" aria-label="Free keyword suggestions" className="max-h-64 overflow-y-auto p-1">
                            {showLoading ? (
                                <li className="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
                                    <LoaderCircle className="h-4 w-4 animate-spin" /> Loading keywords...
                                </li>
                            ) : showError ? (
                                <li className="px-3 py-2 text-sm text-muted-foreground">Keyword suggestions are unavailable.</li>
                            ) : (
                                visibleSuggestions.map((suggestion, index) => (
                                    <li
                                        id={`${listboxId}-${index}`}
                                        key={suggestion.value}
                                        role="option"
                                        aria-selected={highlightedIndex === index}
                                        tabIndex={-1}
                                        className={cn(
                                            'flex w-full cursor-pointer items-center gap-2 rounded-sm px-3 py-2 text-left text-sm',
                                            highlightedIndex === index ? 'bg-accent text-accent-foreground' : 'hover:bg-accent',
                                        )}
                                        onMouseDown={(event) => event.preventDefault()}
                                        onMouseEnter={() => setHighlightedKeyword(suggestion.value)}
                                        onClick={() => selectKeyword(suggestion.value)}
                                    >
                                        <Tag className="h-3.5 w-3.5 shrink-0" />
                                        <span className="min-w-0 flex-1 truncate">{suggestion.value}</span>
                                        <span className="text-xs text-muted-foreground tabular-nums">{suggestion.count}</span>
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>
                )}
            </form>
            <p className="text-xs text-muted-foreground">
                Choose a suggestion for an exact keyword filter, or press Enter for a text search.
                {basePath === '/igsn-search' && ' Use * to replace any number of characters.'}
            </p>
        </div>
    );
}
