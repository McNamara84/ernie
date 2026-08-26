import { Search, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

/**
 * Minimum number of characters required for search query.
 * Must match the backend constant in IgsnController.php.
 */
const MIN_SEARCH_LENGTH = 3;

/**
 * Debounce delay in milliseconds before triggering search.
 * Allows users to finish typing before search is triggered.
 */
const SEARCH_DEBOUNCE_MS = 1000;

// ============================================================================
// Types
// ============================================================================

export interface IgsnFilterOptions {
    prefixes: string[];
    statuses: string[];
    datacenters: Array<{ id: number; name: string }>;
}

export interface IgsnFilterState {
    prefix?: string;
    status?: string;
    search?: string;
    datacenter_id?: number;
    without_datacenter?: boolean;
}

interface IgsnFiltersProps {
    /** Current filter state from the URL / server */
    filters: IgsnFilterState;
    /** Called when any filter changes (search, prefix, status) */
    onFilterChange: (filters: IgsnFilterState) => void;
    /** Available filter options provided by the parent via Inertia server props */
    filterOptions: IgsnFilterOptions | null;
    /** Number of results after filtering */
    resultCount: number;
    /** Total number of results without filtering */
    totalCount: number;
    /** Disables the controls during loading */
    isLoading?: boolean;
}

// ============================================================================
// Component
// ============================================================================

export function IgsnFilters({ filters, onFilterChange, filterOptions, resultCount, totalCount, isLoading = false }: IgsnFiltersProps) {
    // Local state for search input (for immediate UI feedback)
    const [searchInput, setSearchInput] = useState(filters.search || '');
    const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const filtersRef = useRef(filters);

    // Keep filtersRef in sync so debounced callbacks always see the latest state
    useEffect(() => {
        filtersRef.current = filters;
    }, [filters]);

    // Local states for Select components to ensure proper synchronization
    const [prefixValue, setPrefixValue] = useState(filters.prefix || 'all');
    const [datacenterValue, setDatacenterValue] = useState(
        filters.without_datacenter ? 'without' : filters.datacenter_id ? String(filters.datacenter_id) : 'all',
    );
    const [statusValue, setStatusValue] = useState(filters.status || 'all');

    // Sync Select values when filters change externally
    useEffect(() => {
        setPrefixValue(filters.prefix || 'all');
        setDatacenterValue(filters.without_datacenter ? 'without' : filters.datacenter_id ? String(filters.datacenter_id) : 'all');
        setStatusValue(filters.status || 'all');
    }, [filters.prefix, filters.datacenter_id, filters.without_datacenter, filters.status]);

    // Sync search input with filters when filters change externally
    useEffect(() => {
        setSearchInput(filters.search || '');
    }, [filters.search]);

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

    // Debounced search handler
    const handleSearchChange = useCallback(
        (value: string) => {
            setSearchInput(value);

            // Clear existing timeout
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
                searchTimeoutRef.current = undefined;
            }

            // Empty input clears the search immediately
            if (value.trim().length === 0) {
                const newFilters = { ...filtersRef.current };
                delete newFilters.search;
                filtersRef.current = newFilters;
                onFilterChange(newFilters);
                return;
            }

            // Below minimum length: clear active search filter to stay in sync
            if (value.trim().length < MIN_SEARCH_LENGTH) {
                if (filtersRef.current.search) {
                    const newFilters = { ...filtersRef.current };
                    delete newFilters.search;
                    filtersRef.current = newFilters;
                    onFilterChange(newFilters);
                }
                return;
            }

            // Debounce: wait to allow users to finish typing
            // Read from filtersRef to avoid stale closure when prefix/status changed during debounce
            searchTimeoutRef.current = setTimeout(() => {
                onFilterChange({ ...filtersRef.current, search: value.trim() });
            }, SEARCH_DEBOUNCE_MS);
        },
        [onFilterChange],
    );

    const handlePrefixChange = useCallback(
        (value: string) => {
            // Flush any pending debounced search instead of dropping it
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
                searchTimeoutRef.current = undefined;
            }
            setPrefixValue(value);
            const newFilters = { ...filtersRef.current };
            if (value && value !== 'all') {
                newFilters.prefix = value;
            } else {
                delete newFilters.prefix;
            }
            // Include pending search term if it meets minimum length
            const pendingSearch = searchInputRef.current?.value.trim() ?? '';
            if (pendingSearch.length >= MIN_SEARCH_LENGTH) {
                newFilters.search = pendingSearch;
            }
            filtersRef.current = newFilters;
            onFilterChange(newFilters);
        },
        [onFilterChange],
    );

    const handleStatusChange = useCallback(
        (value: string) => {
            // Flush any pending debounced search instead of dropping it
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
                searchTimeoutRef.current = undefined;
            }
            setStatusValue(value);
            const newFilters = { ...filtersRef.current };
            if (value && value !== 'all') {
                newFilters.status = value;
            } else {
                delete newFilters.status;
            }
            // Include pending search term if it meets minimum length
            const pendingSearch = searchInputRef.current?.value.trim() ?? '';
            if (pendingSearch.length >= MIN_SEARCH_LENGTH) {
                newFilters.search = pendingSearch;
            }
            filtersRef.current = newFilters;
            onFilterChange(newFilters);
        },
        [onFilterChange],
    );

    const handleDatacenterChange = useCallback(
        (value: string) => {
            // Flush any pending debounced search instead of dropping it
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
                searchTimeoutRef.current = undefined;
            }
            setDatacenterValue(value);
            const newFilters = { ...filtersRef.current };
            delete newFilters.datacenter_id;
            delete newFilters.without_datacenter;

            if (value === 'without') {
                newFilters.without_datacenter = true;
            } else if (value && value !== 'all') {
                newFilters.datacenter_id = Number(value);
            }

            // Include pending search term if it meets minimum length
            const pendingSearch = searchInputRef.current?.value.trim() ?? '';
            if (pendingSearch.length >= MIN_SEARCH_LENGTH) {
                newFilters.search = pendingSearch;
            }
            filtersRef.current = newFilters;
            onFilterChange(newFilters);
        },
        [onFilterChange],
    );

    const removeFilter = useCallback(
        (key: keyof IgsnFilterState) => {
            const newFilters = { ...filtersRef.current };
            delete newFilters[key];
            if (key === 'search') {
                setSearchInput('');
                if (searchTimeoutRef.current) {
                    clearTimeout(searchTimeoutRef.current);
                    searchTimeoutRef.current = undefined;
                }
            }
            if (key === 'prefix') {
                setPrefixValue('all');
            }
            if (key === 'status') {
                setStatusValue('all');
            }
            if (key === 'datacenter_id' || key === 'without_datacenter') {
                delete newFilters.datacenter_id;
                delete newFilters.without_datacenter;
                setDatacenterValue('all');
            }
            filtersRef.current = newFilters;
            onFilterChange(newFilters);
        },
        [onFilterChange],
    );

    const clearAllFilters = useCallback(() => {
        setSearchInput('');
        setPrefixValue('all');
        setDatacenterValue('all');
        setStatusValue('all');
        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current);
            searchTimeoutRef.current = undefined;
        }
        filtersRef.current = {};
        onFilterChange({});
    }, [onFilterChange]);

    // Computed values
    const activeFilterCount = Object.keys(filters).filter((key) => {
        const value = filters[key as keyof IgsnFilterState];
        return value !== undefined && value !== null && value !== '';
    }).length;

    const hasActiveFilters = activeFilterCount > 0;
    const isFiltered = resultCount !== totalCount;

    const formatFilterLabel = useCallback(
        (key: keyof IgsnFilterState, value: unknown): string => {
            const labelMap: Record<string, string> = {
                prefix: 'Prefix',
                status: 'Status',
                search: 'Search',
                datacenter_id: 'Datacenter',
            };

            const label = labelMap[key] || key;

            if (key === 'status' && typeof value === 'string') {
                return `${label}: ${value.charAt(0).toUpperCase() + value.slice(1)}`;
            }

            if (key === 'datacenter_id' && filterOptions?.datacenters) {
                const datacenter = filterOptions.datacenters.find((option) => option.id === value);
                return `${label}: ${datacenter?.name ?? String(value)}`;
            }

            if (key === 'without_datacenter') {
                return 'Datacenter: Without Datacenter';
            }

            return `${label}: ${String(value)}`;
        },
        [filterOptions],
    );

    return (
        <div className="space-y-3">
            {/* Filter Controls */}
            <div className="flex flex-wrap items-center gap-2">
                {/* Search Input */}
                <div className="relative w-full sm:w-auto sm:min-w-[320px]">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        ref={searchInputRef}
                        type="search"
                        placeholder={`Search IGSN or title (min. ${MIN_SEARCH_LENGTH} chars)...`}
                        value={searchInput}
                        onChange={(e) => handleSearchChange(e.target.value)}
                        className="pl-9"
                        disabled={isLoading}
                        aria-label="Search IGSNs by IGSN or title"
                    />
                </div>

                {/* Prefix Select */}
                <Select value={prefixValue} onValueChange={handlePrefixChange} disabled={isLoading || !filterOptions}>
                    <SelectTrigger size="sm" className="w-full sm:w-[180px]" aria-label="Filter by IGSN prefix">
                        <SelectValue placeholder="All Prefixes" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Prefixes</SelectItem>
                        {filterOptions?.prefixes?.map((prefix) => (
                            <SelectItem key={prefix} value={prefix}>
                                {prefix}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Datacenter Select */}
                <Select value={datacenterValue} onValueChange={handleDatacenterChange} disabled={isLoading || !filterOptions}>
                    <SelectTrigger size="sm" className="w-full sm:w-[180px]" aria-label="Filter by datacenter">
                        <SelectValue placeholder="Datacenter" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Datacenters</SelectItem>
                        <SelectItem value="without">Without Datacenter</SelectItem>
                        {filterOptions?.datacenters?.map((datacenter) => (
                            <SelectItem key={datacenter.id} value={String(datacenter.id)}>
                                {datacenter.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Status Select */}
                <Select value={statusValue} onValueChange={handleStatusChange} disabled={isLoading || !filterOptions}>
                    <SelectTrigger size="sm" className="w-full sm:w-[180px]" aria-label="Filter by upload status">
                        <SelectValue placeholder="All Statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        {filterOptions?.statuses?.map((status) => (
                            <SelectItem key={status} value={status}>
                                {status.charAt(0).toUpperCase() + status.slice(1)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Clear All Filters Button */}
                {hasActiveFilters && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={clearAllFilters}
                        disabled={isLoading}
                        className="text-muted-foreground hover:text-foreground"
                    >
                        Clear All
                    </Button>
                )}
            </div>

            {/* Active Filters & Result Count Row */}
            {(hasActiveFilters || isFiltered) && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    {/* Active Filter Badges */}
                    {hasActiveFilters && (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm text-muted-foreground">Active filters:</span>
                            {Object.entries(filters).map(([key, value]) => {
                                if (value === undefined || value === null || value === '') {
                                    return null;
                                }

                                return (
                                    <Badge key={key} variant="secondary" className="gap-1 pr-1">
                                        <span>{formatFilterLabel(key as keyof IgsnFilterState, value)}</span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-auto p-0.5 hover:bg-transparent"
                                            onClick={() => removeFilter(key as keyof IgsnFilterState)}
                                            aria-label={`Remove ${key} filter`}
                                        >
                                            <X className="h-3 w-3" />
                                        </Button>
                                    </Badge>
                                );
                            })}
                        </div>
                    )}

                    {/* Result Counter */}
                    <div className="ml-auto text-sm text-muted-foreground">
                        {isFiltered ? (
                            <span>
                                Showing <span className="font-semibold text-foreground">{resultCount}</span> of{' '}
                                <span className="font-semibold text-foreground">{totalCount}</span> samples
                            </span>
                        ) : (
                            <span>
                                <span className="font-semibold text-foreground">{totalCount}</span> samples total
                            </span>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
