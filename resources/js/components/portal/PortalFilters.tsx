import { ChevronLeft, ChevronRight, Filter, Search, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import type { PortalFilters, PortalTypeFilter } from '@/types/portal';

interface PortalFiltersProps {
    filters: PortalFilters;
    onSearchChange: (query: string) => void;
    onTypeChange: (type: PortalTypeFilter) => void;
    onClearFilters: () => void;
    hasActiveFilters: boolean;
    isCollapsed: boolean;
    onToggleCollapse: () => void;
    totalResults: number;
}

export function PortalFilters({
    filters,
    onSearchChange,
    onTypeChange,
    onClearFilters,
    hasActiveFilters,
    isCollapsed,
    onToggleCollapse,
    totalResults,
}: PortalFiltersProps) {
    const [searchInput, setSearchInput] = useState(filters.query ?? '');

    // Sync local state when filters change externally
    useEffect(() => {
        setSearchInput(filters.query ?? '');
    }, [filters.query]);

    // Debounced search
    const handleSearchSubmit = useCallback(
        (e: React.FormEvent) => {
            e.preventDefault();
            onSearchChange(searchInput);
        },
        [searchInput, onSearchChange],
    );

    const handleClearSearch = useCallback(() => {
        setSearchInput('');
        onSearchChange('');
    }, [onSearchChange]);

    if (isCollapsed) {
        return (
            <div className="flex h-full flex-col items-center border-r bg-muted/30 py-4">
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onToggleCollapse}
                    className="mb-4"
                    aria-label="Expand filters"
                >
                    <ChevronRight className="h-4 w-4" />
                </Button>
                <div className="flex flex-col items-center gap-4">
                    <Filter className="h-5 w-5 text-muted-foreground" />
                    <Search className="h-5 w-5 text-muted-foreground" />
                </div>
                {hasActiveFilters && (
                    <div className="mt-4 h-2 w-2 rounded-full bg-primary" title="Filters active" />
                )}
            </div>
        );
    }

    return (
        <div className="flex h-full w-80 flex-col border-r bg-muted/30">
            {/* Header */}
            <div className="flex items-center justify-between border-b px-4 py-3">
                <div className="flex items-center gap-2">
                    <Filter className="h-4 w-4" />
                    <span className="font-semibold">Filters</span>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onToggleCollapse}
                    aria-label="Collapse filters"
                >
                    <ChevronLeft className="h-4 w-4" />
                </Button>
            </div>

            <ScrollArea className="flex-1">
                <div className="space-y-6 p-4">
                    {/* Search Input */}
                    <div className="space-y-2">
                        <Label htmlFor="portal-search" className="text-sm font-medium">
                            Search
                        </Label>
                        <form onSubmit={handleSearchSubmit} className="flex gap-2">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    id="portal-search"
                                    type="text"
                                    placeholder="Search datasets..."
                                    value={searchInput}
                                    onChange={(e) => setSearchInput(e.target.value)}
                                    className="pl-9 pr-8"
                                />
                                {searchInput && (
                                    <button
                                        type="button"
                                        onClick={handleClearSearch}
                                        className="absolute right-2 top-1/2 -translate-y-1/2 rounded-sm p-1 hover:bg-muted"
                                        aria-label="Clear search"
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                )}
                            </div>
                            <Button type="submit" size="sm">
                                Search
                            </Button>
                        </form>
                        <p className="text-xs text-muted-foreground">
                            Search in titles, authors, DOIs, and descriptions
                        </p>
                    </div>

                    {/* Type Filter */}
                    <div className="space-y-3">
                        <Label className="text-sm font-medium">Resource Type</Label>
                        <RadioGroup
                            value={filters.type}
                            onValueChange={(value) => onTypeChange(value as PortalTypeFilter)}
                            className="space-y-2"
                        >
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="all" id="type-all" />
                                <Label htmlFor="type-all" className="cursor-pointer font-normal">
                                    All Resources
                                </Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="doi" id="type-doi" />
                                <Label htmlFor="type-doi" className="cursor-pointer font-normal">
                                    DOI Resources (Datasets, Software, etc.)
                                </Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="igsn" id="type-igsn" />
                                <Label htmlFor="type-igsn" className="cursor-pointer font-normal">
                                    IGSN Samples (Physical Objects)
                                </Label>
                            </div>
                        </RadioGroup>
                    </div>

                    {/* Clear Filters */}
                    {hasActiveFilters && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onClearFilters}
                            className="w-full"
                        >
                            <X className="mr-2 h-4 w-4" />
                            Clear All Filters
                        </Button>
                    )}
                </div>
            </ScrollArea>

            {/* Footer with result count */}
            <div className="border-t px-4 py-3">
                <p className={cn('text-sm', hasActiveFilters ? 'font-medium text-primary' : 'text-muted-foreground')}>
                    {totalResults.toLocaleString()} {totalResults === 1 ? 'result' : 'results'}
                </p>
            </div>
        </div>
    );
}
