import { format, parseISO } from 'date-fns';
import { Minus, Plus } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TIMEZONE_OPTIONS } from '@/lib/date-utils';
import { cn } from '@/lib/utils';

import { SelectField } from './select-field';

interface Option {
    value: string;
    label: string;
    description?: string;
}

interface DateFieldProps {
    id: string;
    startDate: string | null;
    endDate: string | null;
    dateType: string;
    startTime: string | null;
    endTime: string | null;
    startTimezone: string | null;
    endTimezone: string | null;
    options: Option[];
    dateTypeDescription?: string;
    onStartDateChange: (value: string) => void;
    onEndDateChange: (value: string) => void;
    onStartTimeChange: (value: string) => void;
    onEndTimeChange: (value: string) => void;
    onStartTimezoneChange: (value: string) => void;
    onEndTimezoneChange: (value: string) => void;
    onTypeChange: (value: string) => void;
    onAdd: () => void;
    onRemove: () => void;
    isFirst: boolean;
    canAdd?: boolean;
    className?: string;
}

export function DateField({
    id,
    startDate,
    endDate,
    dateType,
    startTime,
    endTime,
    startTimezone,
    endTimezone,
    options,
    dateTypeDescription,
    onStartDateChange,
    onEndDateChange,
    onStartTimeChange,
    onEndTimeChange,
    onStartTimezoneChange,
    onEndTimezoneChange,
    onTypeChange,
    onAdd,
    onRemove,
    isFirst,
    canAdd = true,
    className,
}: DateFieldProps) {
    // Only "valid" date type should have start and end dates (date range)
    // All other types represent a single point in time
    const isDateRange = dateType === 'valid';

    // Check if any time/timezone is set to determine whether to show time fields
    const hasTimeInfo = Boolean(startTime || endTime || startTimezone || endTimezone);

    // Track previous dateType to detect actual transitions from 'valid' to non-'valid'
    const prevDateTypeRef = useRef<string>(dateType);

    // Clear endDate, endTime, and endTimezone when switching away from 'valid' date type to prevent stale data
    useEffect(() => {
        const prevDateType = prevDateTypeRef.current;

        // Only clear endDate when transitioning FROM 'valid' TO a non-'valid' type
        // This prevents race conditions and unnecessary calls when endDate is already empty
        if (prevDateType === 'valid' && dateType !== 'valid') {
            if (endDate) onEndDateChange('');
            if (endTime) onEndTimeChange('');
            if (endTimezone) onEndTimezoneChange('none');
        }

        // Update the ref for the next render
        prevDateTypeRef.current = dateType;
    }, [dateType, endDate, endTime, endTimezone, onEndDateChange, onEndTimeChange, onEndTimezoneChange]);

    return (
        <div className={cn('space-y-3', className)}>
            {/* Main row: Date(s) + DateType + Add/Remove button */}
            <div
                className={cn(
                    'grid gap-4',
                    isDateRange ? 'md:grid-cols-[1fr_1fr_180px_40px]' : 'md:grid-cols-[1fr_180px_40px]',
                )}
            >
                <div className="space-y-2">
                    {isFirst && (
                        <Label htmlFor={`${id}-${isDateRange ? 'startDate' : 'date'}`}>
                            {isDateRange ? 'Start Date' : 'Date'}
                            {dateType === 'created' && <span className="text-destructive"> *</span>}
                        </Label>
                    )}
                    <DatePicker
                        id={`${id}-${isDateRange ? 'startDate' : 'date'}`}
                        value={startDate ? parseISO(startDate) : undefined}
                        onChange={(date) => onStartDateChange(date ? format(date, 'yyyy-MM-dd') : '')}
                        placeholder="Select date"
                        dateFormat="yyyy-MM-dd"
                        required={dateType === 'created'}
                    />
                </div>
                {isDateRange && (
                    <div className="space-y-2">
                        {isFirst && <Label htmlFor={`${id}-endDate`}>End Date</Label>}
                        <DatePicker
                            id={`${id}-endDate`}
                            value={endDate ? parseISO(endDate) : undefined}
                            onChange={(date) => onEndDateChange(date ? format(date, 'yyyy-MM-dd') : '')}
                            placeholder="Select date"
                            dateFormat="yyyy-MM-dd"
                        />
                    </div>
                )}
                <div>
                    <SelectField
                        id={`${id}-dateType`}
                        label="Date Type"
                        value={dateType}
                        onValueChange={onTypeChange}
                        options={options}
                        hideLabel={!isFirst}
                        required
                    />
                    {dateTypeDescription && <p className="mt-1 text-xs text-muted-foreground">{dateTypeDescription}</p>}
                </div>
                <div className="flex items-end">
                    {isFirst ? (
                        <Button type="button" variant="outline" size="icon" aria-label="Add date" onClick={onAdd} disabled={!canAdd}>
                            <Plus className="h-4 w-4" />
                        </Button>
                    ) : (
                        <Button type="button" variant="outline" size="icon" aria-label="Remove date" onClick={onRemove}>
                            <Minus className="h-4 w-4" />
                        </Button>
                    )}
                </div>
            </div>

            {/* Time/Timezone row: shown when a date is selected */}
            {(startDate || hasTimeInfo) && (
                <div className="grid grid-cols-1 md:grid-cols-[1fr_1fr] gap-4 pl-4 border-l-2 border-muted">
                    <div className="space-y-2">
                        <Label htmlFor={`${id}-startTime`} className="text-xs text-muted-foreground">
                            {isDateRange ? 'Start Time' : 'Time'} (optional)
                        </Label>
                        <Input
                            id={`${id}-startTime`}
                            type="time"
                            value={startTime ?? ''}
                            onChange={(e) => onStartTimeChange(e.target.value)}
                            className="h-9"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={`${id}-startTimezone`} className="text-xs text-muted-foreground">
                            {isDateRange ? 'Start Timezone' : 'Timezone'} (optional)
                        </Label>
                        <Select value={startTimezone ?? 'none'} onValueChange={onStartTimezoneChange}>
                            <SelectTrigger id={`${id}-startTimezone`} className="h-9">
                                <SelectValue placeholder="No timezone" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">No timezone</SelectItem>
                                {TIMEZONE_OPTIONS.map((tz) => (
                                    <SelectItem key={tz.value} value={tz.value}>
                                        {tz.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    {isDateRange && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor={`${id}-endTime`} className="text-xs text-muted-foreground">
                                    End Time (optional)
                                </Label>
                                <Input
                                    id={`${id}-endTime`}
                                    type="time"
                                    value={endTime ?? ''}
                                    onChange={(e) => onEndTimeChange(e.target.value)}
                                    className="h-9"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`${id}-endTimezone`} className="text-xs text-muted-foreground">
                                    End Timezone (optional)
                                </Label>
                                <Select value={endTimezone ?? 'none'} onValueChange={onEndTimezoneChange}>
                                    <SelectTrigger id={`${id}-endTimezone`} className="h-9">
                                        <SelectValue placeholder="No timezone" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No timezone</SelectItem>
                                        {TIMEZONE_OPTIONS.map((tz) => (
                                            <SelectItem key={tz.value} value={tz.value}>
                                                {tz.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

export default DateField;
