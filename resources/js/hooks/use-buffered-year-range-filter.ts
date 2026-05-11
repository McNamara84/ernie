import { useCallback, useEffect, useMemo, useState } from 'react';

import { formatYearInput, parseYearInput, type YearRangeBounds } from '@/lib/year-range-input';

interface YearRangeFilterState {
    year_from?: number;
    year_to?: number;
}

interface UseBufferedYearRangeFilterOptions<TFilters extends YearRangeFilterState> {
    filters: TFilters;
    onFilterChange: (filters: TFilters) => void;
    bounds?: YearRangeBounds;
}

interface UseBufferedYearRangeFilterResult {
    yearFromInput: string;
    yearToInput: string;
    hasYearRangeInput: boolean;
    hasEffectiveYearRangeChange: boolean;
    yearRangeMin?: number | null;
    yearRangeMax?: number | null;
    handleYearFromChange: (value: string) => void;
    handleYearToChange: (value: string) => void;
    applyYearRange: () => void;
    clearYearRange: () => void;
}

export function useBufferedYearRangeFilter<TFilters extends YearRangeFilterState>({
    filters,
    onFilterChange,
    bounds,
}: UseBufferedYearRangeFilterOptions<TFilters>): UseBufferedYearRangeFilterResult {
    const [yearFromInput, setYearFromInput] = useState(() => formatYearInput(filters.year_from));
    const [yearToInput, setYearToInput] = useState(() => formatYearInput(filters.year_to));

    const committedYearFrom = formatYearInput(filters.year_from);
    const committedYearTo = formatYearInput(filters.year_to);
    const parsedYearFrom = useMemo(() => parseYearInput(yearFromInput, bounds), [bounds, yearFromInput]);
    const parsedYearTo = useMemo(() => parseYearInput(yearToInput, bounds), [bounds, yearToInput]);

    useEffect(() => {
        setYearFromInput(committedYearFrom);
        setYearToInput(committedYearTo);
    }, [committedYearFrom, committedYearTo]);

    const applyYearRange = useCallback(() => {
        if (parsedYearFrom === filters.year_from && parsedYearTo === filters.year_to) {
            return;
        }

        const nextFilters: TFilters = { ...filters };

        if (parsedYearFrom !== undefined) {
            nextFilters.year_from = parsedYearFrom;
        } else {
            delete nextFilters.year_from;
        }

        if (parsedYearTo !== undefined) {
            nextFilters.year_to = parsedYearTo;
        } else {
            delete nextFilters.year_to;
        }

        onFilterChange(nextFilters);
    }, [filters, onFilterChange, parsedYearFrom, parsedYearTo]);

    const clearYearRange = useCallback(() => {
        setYearFromInput('');
        setYearToInput('');

        if (filters.year_from === undefined && filters.year_to === undefined) {
            return;
        }

        const nextFilters: TFilters = { ...filters };
        delete nextFilters.year_from;
        delete nextFilters.year_to;
        onFilterChange(nextFilters);
    }, [filters, onFilterChange]);

    return {
        yearFromInput,
        yearToInput,
        hasYearRangeInput: yearFromInput !== '' || yearToInput !== '',
        hasEffectiveYearRangeChange: parsedYearFrom !== filters.year_from || parsedYearTo !== filters.year_to,
        yearRangeMin: bounds?.min,
        yearRangeMax: bounds?.max,
        handleYearFromChange: setYearFromInput,
        handleYearToChange: setYearToInput,
        applyYearRange,
        clearYearRange,
    };
}