import { Calendar, Search, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ResourceFilterOptions, ResourceFilterState } from '@/types/resources';

/**
 * Minimum number of characters required for search query.
 * Search requests are only triggered when the input reaches this length.
 */
const MIN_SEARCH_LENGTH = 3;

/**
 * Debounce delay in milliseconds before triggering search.
 * Increased to allow users to finish typing before search is triggered.
 */
const SEARCH_DEBOUNCE_MS = 1000;

interface ResourcesFiltersProps {
    filters: ResourceFilterState;
    onFilterChange: (filters: ResourceFilterState) => void;
    filterOptions: ResourceFilterOptions | null;
    resultCount: number;
    totalCount: number;
    isLoading?: boolean;
}

export function ResourcesFilters({
    filters,
    onFilterChange,
    filterOptions,
    resultCount,
    totalCount,
    isLoading = false,
}: ResourcesFiltersProps) {
    const activeFilterCount = Object.keys(filters).filter(
        key => {
            const value = filters[key as keyof ResourceFilterState];
            if (Array.isArray(value)) {
                return value.length > 0;
            }
            return value !== undefined && value !== null && value !== '';
        }
    ).length;

    const hasActiveFilters = activeFilterCount > 0;
    const isFiltered = resultCount !== totalCount;

    const clearAllFilters = useCallback(() => {
        onFilterChange({});
    }, [onFilterChange]);

    const removeFilter = useCallback((key: keyof ResourceFilterState) => {
        const newFilters = { ...filters };
        delete newFilters[key];
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    // Local state for search input (for immediate UI feedback)
    const [searchInput, setSearchInput] = useState(filters.search || '');
    const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const searchInputRef = useRef<HTMLInputElement>(null);
    
    // Local states for Select components to ensure proper synchronization
    const [resourceTypeValue, setResourceTypeValue] = useState(filters.resource_type?.[0] || 'all');
    const [statusValue, setStatusValue] = useState(filters.status?.[0] || 'all');
    const [curatorValue, setCuratorValue] = useState(filters.curator?.[0] || 'all');
    
    // Sync Select values when filters change externally
    useEffect(() => {
        setResourceTypeValue(filters.resource_type?.[0] || 'all');
        setStatusValue(filters.status?.[0] || 'all');
        setCuratorValue(filters.curator?.[0] || 'all');
    }, [filters.resource_type, filters.status, filters.curator]);

    // Debounced search handler
    const handleSearchChange = useCallback((value: string) => {
        setSearchInput(value);

        // Clear existing timeout
        if (searchTimeoutRef.current) {
            window.clearTimeout(searchTimeoutRef.current);
        }

        // Only trigger search if:
        // 1. Value is empty (to clear search)
        // 2. Value has at least MIN_SEARCH_LENGTH characters
        if (value.trim().length === 0) {
            const newFilters = { ...filters };
            delete newFilters.search;
            onFilterChange(newFilters);
            return;
        }

        if (value.trim().length < MIN_SEARCH_LENGTH) {
            // Don't search yet, but keep the input value
            return;
        }

        // Debounce: wait longer to allow users to finish typing
        searchTimeoutRef.current = setTimeout(() => {
            const newFilters = { ...filters };
            newFilters.search = value.trim();
            onFilterChange(newFilters);
        }, SEARCH_DEBOUNCE_MS);
    }, [filters, onFilterChange]);

    // Sync search input with filters when filters change externally
    useEffect(() => {
        setSearchInput(filters.search || '');
    }, [filters.search]);

    // Restore focus after loading completes
    useEffect(() => {
        if (!isLoading && searchInput.trim().length >= MIN_SEARCH_LENGTH) {
            // Small delay to ensure the page has fully rendered
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

    const handleResourceTypeChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value && value !== 'all') {
            newFilters.resource_type = [value];
        } else {
            delete newFilters.resource_type;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleStatusChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value && value !== 'all') {
            newFilters.status = [value];
        } else {
            delete newFilters.status;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleCuratorChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value && value !== 'all') {
            newFilters.curator = [value];
        } else {
            delete newFilters.curator;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleYearFromChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        const year = parseInt(value, 10);
        if (!Number.isNaN(year) && year > 0) {
            newFilters.year_from = year;
        } else {
            delete newFilters.year_from;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleYearToChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        const year = parseInt(value, 10);
        if (!Number.isNaN(year) && year > 0) {
            newFilters.year_to = year;
        } else {
            delete newFilters.year_to;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleCreatedFromChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value) {
            newFilters.created_from = value;
        } else {
            delete newFilters.created_from;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleCreatedToChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value) {
            newFilters.created_to = value;
        } else {
            delete newFilters.created_to;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleUpdatedFromChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value) {
            newFilters.updated_from = value;
        } else {
            delete newFilters.updated_from;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleUpdatedToChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value) {
            newFilters.updated_to = value;
        } else {
            delete newFilters.updated_to;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const formatFilterLabel = useCallback((key: keyof ResourceFilterState, value: unknown): string => {
        const labelMap: Record<string, string> = {
            resource_type: 'Type',
            status: 'Status',
            curator: 'Curator',
            search: 'Search',
            year_from: 'Year from',
            year_to: 'Year to',
            created_from: 'Created from',
            created_to: 'Created to',
            updated_from: 'Updated from',
            updated_to: 'Updated to',
        };

        const label = labelMap[key] || key;

        if (Array.isArray(value)) {
            // For resource types, show the name instead of slug
            if (key === 'resource_type' && filterOptions?.resource_types) {
                const names = value.map(slug => {
                    const type = filterOptions.resource_types.find(t => t.slug === slug);
                    return type?.name || slug;
                });
                return `${label}: ${names.join(', ')}`;
            }
            return `${label}: ${value.join(', ')}`;
        }

        return `${label}: ${String(value)}`;
    }, [filterOptions]);

    return (
        <div className="space-y-4 mb-4">
            {/* Filter Controls */}
            <div className="flex flex-wrap gap-2 items-center">
                {/* Search Input */}
                <div className="relative w-full sm:w-auto sm:min-w-[280px]">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                        ref={searchInputRef}
                        type="search"
                        placeholder={`Search title or DOI (min. ${MIN_SEARCH_LENGTH} characters)...`}
                        value={searchInput}
                        onChange={(e) => handleSearchChange(e.target.value)}
                        className="pl-9"
                        disabled={isLoading}
                        aria-label="Search resources by title or DOI"
                    />
                </div>

                {/* Resource Type Select */}
                <Select
                    value={resourceTypeValue}
                    onValueChange={handleResourceTypeChange}
                    disabled={isLoading || !filterOptions}
                >
                    <SelectTrigger className="w-full sm:w-[180px]" aria-label="Filter by resource type">
                        <SelectValue placeholder="Resource Type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Types</SelectItem>
                        {filterOptions?.resource_types?.map(type => (
                            <SelectItem key={type.slug} value={type.slug}>
                                {type.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Status Select */}
                <Select
                    value={statusValue}
                    onValueChange={handleStatusChange}
                    disabled={isLoading || !filterOptions}
                >
                    <SelectTrigger className="w-full sm:w-[180px]" aria-label="Filter by publication status">
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        {filterOptions?.statuses?.map(status => (
                            <SelectItem key={status} value={status}>
                                {status.charAt(0).toUpperCase() + status.slice(1)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Curator Select */}
                <Select
                    value={curatorValue}
                    onValueChange={handleCuratorChange}
                    disabled={isLoading || !filterOptions}
                >
                    <SelectTrigger className="w-full sm:w-[180px]" aria-label="Filter by curator">
                        <SelectValue placeholder="Curator" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Curators</SelectItem>
                        {filterOptions?.curators?.map(curator => (
                            <SelectItem key={curator} value={curator}>
                                {curator}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Publication Year Range Popover */}
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            variant="outline"
                            size="default"
                            className={`w-full sm:w-[180px] justify-start font-normal ${
                                (filters.year_from || filters.year_to) ? 'border-primary' : ''
                            }`}
                            disabled={isLoading || !filterOptions}
                            aria-label="Filter by publication year range"
                        >
                            <Calendar className="mr-2 h-4 w-4" />
                            {filters.year_from || filters.year_to ? (
                                <span>
                                    {filters.year_from || '...'} - {filters.year_to || '...'}
                                </span>
                            ) : (
                                <span>Year Range</span>
                            )}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-80" align="start">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <h4 className="font-medium text-sm">Publication Year Range</h4>
                                <p className="text-xs text-muted-foreground">
                                    Filter resources by their publication year
                                </p>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="year-from" className="text-xs">From Year</Label>
                                    <Input
                                        id="year-from"
                                        type="number"
                                        placeholder={filterOptions?.year_range?.min.toString()}
                                        value={filters.year_from || ''}
                                        onChange={(e) => handleYearFromChange(e.target.value)}
                                        min={filterOptions?.year_range?.min}
                                        max={filterOptions?.year_range?.max}
                                        className="h-9"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="year-to" className="text-xs">To Year</Label>
                                    <Input
                                        id="year-to"
                                        type="number"
                                        placeholder={filterOptions?.year_range?.max.toString()}
                                        value={filters.year_to || ''}
                                        onChange={(e) => handleYearToChange(e.target.value)}
                                        min={filterOptions?.year_range?.min}
                                        max={filterOptions?.year_range?.max}
                                        className="h-9"
                                    />
                                </div>
                            </div>
                            {(filters.year_from || filters.year_to) && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        const newFilters = { ...filters };
                                        delete newFilters.year_from;
                                        delete newFilters.year_to;
                                        onFilterChange(newFilters);
                                    }}
                                    className="w-full"
                                >
                                    Clear Year Range
                                </Button>
                            )}
                        </div>
                    </PopoverContent>
                </Popover>

                {/* Created Date Range Popover */}
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            variant="outline"
                            size="default"
                            className={`w-full sm:w-[180px] justify-start font-normal ${
                                (filters.created_from || filters.created_to) ? 'border-primary' : ''
                            }`}
                            disabled={isLoading}
                            aria-label="Filter by creation date range"
                        >
                            <Calendar className="mr-2 h-4 w-4" />
                            {filters.created_from || filters.created_to ? (
                                <span className="truncate">Created: {filters.created_from || '...'} - {filters.created_to || '...'}</span>
                            ) : (
                                <span>Created Date</span>
                            )}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-80" align="start">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <h4 className="font-medium text-sm">Created Date Range</h4>
                                <p className="text-xs text-muted-foreground">
                                    Filter resources by when they were created in the system
                                </p>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="created-from" className="text-xs">From Date</Label>
                                    <Input
                                        id="created-from"
                                        type="date"
                                        value={filters.created_from || ''}
                                        onChange={(e) => handleCreatedFromChange(e.target.value)}
                                        className="h-9"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="created-to" className="text-xs">To Date</Label>
                                    <Input
                                        id="created-to"
                                        type="date"
                                        value={filters.created_to || ''}
                                        onChange={(e) => handleCreatedToChange(e.target.value)}
                                        className="h-9"
                                    />
                                </div>
                            </div>
                            {(filters.created_from || filters.created_to) && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        const newFilters = { ...filters };
                                        delete newFilters.created_from;
                                        delete newFilters.created_to;
                                        onFilterChange(newFilters);
                                    }}
                                    className="w-full"
                                >
                                    Clear Created Date Range
                                </Button>
                            )}
                        </div>
                    </PopoverContent>
                </Popover>

                {/* Updated Date Range Popover */}
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            variant="outline"
                            size="default"
                            className={`w-full sm:w-[180px] justify-start font-normal ${
                                (filters.updated_from || filters.updated_to) ? 'border-primary' : ''
                            }`}
                            disabled={isLoading}
                            aria-label="Filter by last update date range"
                        >
                            <Calendar className="mr-2 h-4 w-4" />
                            {filters.updated_from || filters.updated_to ? (
                                <span className="truncate">Updated: {filters.updated_from || '...'} - {filters.updated_to || '...'}</span>
                            ) : (
                                <span>Updated Date</span>
                            )}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-80" align="start">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <h4 className="font-medium text-sm">Updated Date Range</h4>
                                <p className="text-xs text-muted-foreground">
                                    Filter resources by when they were last updated
                                </p>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="updated-from" className="text-xs">From Date</Label>
                                    <Input
                                        id="updated-from"
                                        type="date"
                                        value={filters.updated_from || ''}
                                        onChange={(e) => handleUpdatedFromChange(e.target.value)}
                                        className="h-9"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="updated-to" className="text-xs">To Date</Label>
                                    <Input
                                        id="updated-to"
                                        type="date"
                                        value={filters.updated_to || ''}
                                        onChange={(e) => handleUpdatedToChange(e.target.value)}
                                        className="h-9"
                                    />
                                </div>
                            </div>
                            {(filters.updated_from || filters.updated_to) && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        const newFilters = { ...filters };
                                        delete newFilters.updated_from;
                                        delete newFilters.updated_to;
                                        onFilterChange(newFilters);
                                    }}
                                    className="w-full"
                                >
                                    Clear Updated Date Range
                                </Button>
                            )}
                        </div>
                    </PopoverContent>
                </Popover>

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
                <div className="flex flex-wrap gap-2 items-center justify-between">
                    {/* Active Filter Badges */}
                    {hasActiveFilters && (
                        <div className="flex flex-wrap gap-2 items-center">
                            <span className="text-sm text-muted-foreground">Active filters:</span>
                            {Object.entries(filters).map(([key, value]) => {
                                if (value === undefined || value === null || value === '') {
                                    return null;
                                }
                                if (Array.isArray(value) && value.length === 0) {
                                    return null;
                                }

                                return (
                                    <Badge
                                        key={key}
                                        variant="secondary"
                                        className="gap-1 pr-1"
                                    >
                                        <span>{formatFilterLabel(key as keyof ResourceFilterState, value)}</span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-auto p-0.5 hover:bg-transparent"
                                            onClick={() => removeFilter(key as keyof ResourceFilterState)}
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
                    <div className="text-sm text-muted-foreground ml-auto">
                        {isFiltered ? (
                            <span>
                                Showing <span className="font-semibold text-foreground">{resultCount}</span> of{' '}
                                <span className="font-semibold text-foreground">{totalCount}</span> resources
                            </span>
                        ) : (
                            <span>
                                <span className="font-semibold text-foreground">{totalCount}</span> resources total
                            </span>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
