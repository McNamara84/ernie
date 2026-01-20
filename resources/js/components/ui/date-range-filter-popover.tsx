/**
 * Date Range Filter Popover Component
 *
 * A reusable popover component for filtering by date ranges.
 * Uses the shadcn/ui DatePicker component instead of native date inputs.
 */

import { format, parseISO } from 'date-fns';
import { Calendar } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

interface DateRangeFilterPopoverProps {
    /** Label for the filter (e.g., "Created", "Updated") */
    label: string;
    /** Description shown inside the popover */
    description?: string;
    /** Start date value (ISO string: YYYY-MM-DD) */
    fromValue?: string;
    /** End date value (ISO string: YYYY-MM-DD) */
    toValue?: string;
    /** Callback when start date changes */
    onFromChange: (value: string) => void;
    /** Callback when end date changes */
    onToChange: (value: string) => void;
    /** Callback to clear both dates */
    onClear: () => void;
    /** Whether the filter is disabled */
    disabled?: boolean;
    /** Aria label for the trigger button */
    ariaLabel?: string;
}

export function DateRangeFilterPopover({
    label,
    description,
    fromValue,
    toValue,
    onFromChange,
    onToChange,
    onClear,
    disabled = false,
    ariaLabel,
}: DateRangeFilterPopoverProps) {
    const hasValue = fromValue || toValue;

    // Parse ISO strings to Date objects for the DatePicker
    const fromDate = fromValue ? parseISO(fromValue) : undefined;
    const toDate = toValue ? parseISO(toValue) : undefined;

    // Handle date changes - convert Date to ISO string
    const handleFromChange = (date: Date | undefined) => {
        onFromChange(date ? format(date, 'yyyy-MM-dd') : '');
    };

    const handleToChange = (date: Date | undefined) => {
        onToChange(date ? format(date, 'yyyy-MM-dd') : '');
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="default"
                    className={`w-full justify-start font-normal sm:w-[180px] ${hasValue ? 'border-primary' : ''}`}
                    disabled={disabled}
                    aria-label={ariaLabel || `Filter by ${label.toLowerCase()} date range`}
                >
                    <Calendar className="mr-2 h-4 w-4" />
                    {hasValue ? (
                        <span className="truncate">
                            {label}: {fromValue || '...'} - {toValue || '...'}
                        </span>
                    ) : (
                        <span>{label} Date</span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80" align="start">
                <div className="space-y-4">
                    <div className="space-y-2">
                        <h4 className="text-sm font-medium">{label} Date Range</h4>
                        {description && <p className="text-xs text-muted-foreground">{description}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor={`${label.toLowerCase()}-from`} className="text-xs">
                                From Date
                            </Label>
                            <DatePicker
                                id={`${label.toLowerCase()}-from`}
                                value={fromDate}
                                onChange={handleFromChange}
                                placeholder="Select date"
                                dateFormat="yyyy-MM-dd"
                                maxDate={toDate}
                                className="h-9"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${label.toLowerCase()}-to`} className="text-xs">
                                To Date
                            </Label>
                            <DatePicker
                                id={`${label.toLowerCase()}-to`}
                                value={toDate}
                                onChange={handleToChange}
                                placeholder="Select date"
                                dateFormat="yyyy-MM-dd"
                                minDate={fromDate}
                                className="h-9"
                            />
                        </div>
                    </div>
                    {hasValue && (
                        <Button variant="outline" size="sm" onClick={onClear} className="w-full">
                            Clear {label} Date Range
                        </Button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
