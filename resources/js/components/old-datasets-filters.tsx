import { Search, X } from 'lucide-react';
import { useCallback } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { FilterOptions, FilterState } from '@/types/old-datasets';

interface OldDatasetsFiltersProps {
    filters: FilterState;
    onFilterChange: (filters: FilterState) => void;
    filterOptions: FilterOptions | null;
    resultCount: number;
    totalCount: number;
    isLoading?: boolean;
}

export function OldDatasetsFilters({
    filters,
    onFilterChange,
    filterOptions,
    resultCount,
    totalCount,
    isLoading = false,
}: OldDatasetsFiltersProps) {
    const activeFilterCount = Object.keys(filters).filter(
        key => {
            const value = filters[key as keyof FilterState];
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

    const removeFilter = useCallback((key: keyof FilterState) => {
        const newFilters = { ...filters };
        delete newFilters[key];
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

    const handleSearchChange = useCallback((value: string) => {
        const newFilters = { ...filters };
        if (value.trim()) {
            newFilters.search = value.trim();
        } else {
            delete newFilters.search;
        }
        onFilterChange(newFilters);
    }, [filters, onFilterChange]);

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

    const formatFilterLabel = (key: keyof FilterState, value: unknown): string => {
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
            return `${label}: ${value.join(', ')}`;
        }

        return `${label}: ${String(value)}`;
    };

    return (
        <div className="space-y-4 mb-4">
            {/* Filter Controls */}
            <div className="flex flex-wrap gap-2 items-center">
                {/* Search Input */}
                <div className="relative w-full sm:w-auto sm:min-w-[280px]">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                        type="search"
                        placeholder="Search title or DOI..."
                        value={filters.search || ''}
                        onChange={(e) => handleSearchChange(e.target.value)}
                        className="pl-9"
                        disabled={isLoading}
                        aria-label="Search datasets by title or DOI"
                    />
                </div>

                {/* Resource Type Select */}
                <Select
                    value={filters.resource_type?.[0] || 'all'}
                    onValueChange={handleResourceTypeChange}
                    disabled={isLoading || !filterOptions}
                >
                    <SelectTrigger className="w-full sm:w-[180px]" aria-label="Filter by resource type">
                        <SelectValue placeholder="Resource Type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Types</SelectItem>
                        {filterOptions?.resource_types.map(type => (
                            <SelectItem key={type} value={type}>
                                {type}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Status Select */}
                <Select
                    value={filters.status?.[0] || 'all'}
                    onValueChange={handleStatusChange}
                    disabled={isLoading || !filterOptions}
                >
                    <SelectTrigger className="w-full sm:w-[180px]" aria-label="Filter by publication status">
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        {filterOptions?.statuses.map(status => (
                            <SelectItem key={status} value={status}>
                                {status.charAt(0).toUpperCase() + status.slice(1)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Curator Select */}
                <Select
                    value={filters.curator?.[0] || 'all'}
                    onValueChange={handleCuratorChange}
                    disabled={isLoading || !filterOptions}
                >
                    <SelectTrigger className="w-full sm:w-[180px]" aria-label="Filter by curator">
                        <SelectValue placeholder="Curator" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Curators</SelectItem>
                        {filterOptions?.curators.map(curator => (
                            <SelectItem key={curator} value={curator}>
                                {curator}
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
                                        <span>{formatFilterLabel(key as keyof FilterState, value)}</span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-auto p-0.5 hover:bg-transparent"
                                            onClick={() => removeFilter(key as keyof FilterState)}
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
                                <span className="font-semibold text-foreground">{totalCount}</span> datasets
                            </span>
                        ) : (
                            <span>
                                <span className="font-semibold text-foreground">{totalCount}</span> datasets total
                            </span>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
