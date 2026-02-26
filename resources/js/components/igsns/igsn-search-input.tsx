import { Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Input } from '@/components/ui/input';

/**
 * Minimum number of characters required for search query.
 * Search requests are only triggered when the input reaches this length.
 */
const MIN_SEARCH_LENGTH = 3;

/**
 * Debounce delay in milliseconds before triggering search.
 * Allows users to finish typing before search is triggered.
 */
const SEARCH_DEBOUNCE_MS = 1000;

interface IgsnSearchInputProps {
    /** Current search value from the URL / server */
    value: string;
    /** Called with the new search string (empty string = clear) */
    onChange: (value: string) => void;
    /** Number of results after filtering */
    resultCount: number;
    /** Total number of results without filtering */
    totalCount: number;
    /** Disables the input during loading */
    isLoading?: boolean;
}

export function IgsnSearchInput({ value, onChange, resultCount, totalCount, isLoading = false }: IgsnSearchInputProps) {
    const [searchInput, setSearchInput] = useState(value);
    const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const searchInputRef = useRef<HTMLInputElement>(null);

    // Sync input with external value changes
    useEffect(() => {
        setSearchInput(value);
    }, [value]);

    // Restore focus after loading completes when a search is active
    useEffect(() => {
        if (!isLoading && searchInput.trim().length >= MIN_SEARCH_LENGTH) {
            const timeoutId = setTimeout(() => {
                searchInputRef.current?.focus();
            }, 100);

            return () => clearTimeout(timeoutId);
        }
    }, [isLoading, searchInput]);

    // Cleanup timeout on unmount
    useEffect(() => {
        return () => {
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
            }
        };
    }, []);

    const handleSearchChange = useCallback(
        (inputValue: string) => {
            setSearchInput(inputValue);

            // Clear existing timeout
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
            }

            // Empty input clears the search immediately
            if (inputValue.trim().length === 0) {
                onChange('');
                return;
            }

            // Don't search with less than MIN_SEARCH_LENGTH characters
            if (inputValue.trim().length < MIN_SEARCH_LENGTH) {
                return;
            }

            // Debounce the search
            searchTimeoutRef.current = setTimeout(() => {
                onChange(inputValue.trim());
            }, SEARCH_DEBOUNCE_MS);
        },
        [onChange],
    );

    const isFiltered = resultCount !== totalCount;

    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="relative w-full sm:w-auto sm:min-w-[320px]">
                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    ref={searchInputRef}
                    type="search"
                    placeholder={`Search IGSN or title (min. ${MIN_SEARCH_LENGTH} characters)...`}
                    value={searchInput}
                    onChange={(e) => handleSearchChange(e.target.value)}
                    className="pl-9"
                    disabled={isLoading}
                    aria-label="Search IGSNs by IGSN or title"
                />
            </div>

            {isFiltered && (
                <span className="text-sm text-muted-foreground">
                    Showing <span className="font-semibold text-foreground">{resultCount}</span> of{' '}
                    <span className="font-semibold text-foreground">{totalCount}</span> samples
                </span>
            )}
        </div>
    );
}
