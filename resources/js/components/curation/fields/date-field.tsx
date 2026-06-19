import { format, parseISO } from 'date-fns';
import { Minus, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { normalizeTimeForInput, TIMEZONE_OPTIONS } from '@/lib/date-utils';
import { cn } from '@/lib/utils';

import { type DateMode, isDateRangeCapable } from '../utils/date-rules';
import { SelectField } from './select-field';

/**
 * Check if a date string is partial-precision (YYYY or YYYY-MM).
 * These cannot be reliably rendered in a calendar DatePicker without data loss.
 */
function isPartialDate(date: string | null): boolean {
    if (!date) return false;
    return /^\d{4}$/.test(date) || /^\d{4}-\d{2}$/.test(date);
}

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
    dateMode: DateMode;
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
    onDateModeChange: (value: DateMode) => void;
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
    dateMode,
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
    onDateModeChange,
    onAdd,
    onRemove,
    isFirst,
    canAdd = true,
    className,
}: DateFieldProps) {
    const supportsDateRange = isDateRangeCapable(dateType);
    const isDateRange = supportsDateRange && dateMode === 'range';

    // Check if any time/timezone is set to determine whether to show time fields
    const hasTimeInfo = Boolean(startTime || endTime || startTimezone || endTimezone);
    const modeGridClass = supportsDateRange
        ? isDateRange
            ? 'md:grid-cols-[1fr_1fr_180px_180px_40px]'
            : 'md:grid-cols-[1fr_180px_180px_40px]'
        : 'md:grid-cols-[1fr_180px_40px]';

    const handleDateModeChange = (value: string) => {
        if (value === 'single' || value === 'range') {
            onDateModeChange(value);
        }
    };

    return (
        <div className={cn('space-y-3', className)}>
            {/* Main row: Date(s) + DateType + Date mode + Add/Remove button */}
            <div className={cn('grid gap-4', modeGridClass)}>
                <div className="space-y-2">
                    {isFirst && (
                        <Label htmlFor={`${id}-${isDateRange ? 'startDate' : 'date'}`}>
                            {isDateRange ? 'Start Date' : 'Date'}
                            {dateType === 'created' && <span className="font-bold text-destructive"> *</span>}
                        </Label>
                    )}
                    {isPartialDate(startDate) ? (
                        <div className="flex gap-2">
                            <Input
                                id={`${id}-${isDateRange ? 'startDate' : 'date'}`}
                                value={startDate ?? ''}
                                readOnly
                                className="h-9 bg-muted"
                                title="Partial-precision date (year or year-month)"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 shrink-0"
                                aria-label="Clear date"
                                onClick={() => onStartDateChange('')}
                            >
                                <Minus className="h-4 w-4" />
                            </Button>
                        </div>
                    ) : (
                        <DatePicker
                            id={`${id}-${isDateRange ? 'startDate' : 'date'}`}
                            value={startDate ? parseISO(startDate) : undefined}
                            onChange={(date) => onStartDateChange(date ? format(date, 'yyyy-MM-dd') : '')}
                            placeholder="Select date"
                            dateFormat="yyyy-MM-dd"
                            required={dateType === 'created'}
                        />
                    )}
                </div>
                {isDateRange && (
                    <div className="space-y-2">
                        {isFirst && <Label htmlFor={`${id}-endDate`}>End Date</Label>}
                        {isPartialDate(endDate) ? (
                            <div className="flex gap-2">
                                <Input
                                    id={`${id}-endDate`}
                                    value={endDate ?? ''}
                                    readOnly
                                    className="h-9 bg-muted"
                                    title="Partial-precision date (year or year-month)"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-9 w-9 shrink-0"
                                    aria-label="Clear end date"
                                    onClick={() => onEndDateChange('')}
                                >
                                    <Minus className="h-4 w-4" />
                                </Button>
                            </div>
                        ) : (
                            <DatePicker
                                id={`${id}-endDate`}
                                value={endDate ? parseISO(endDate) : undefined}
                                onChange={(date) => onEndDateChange(date ? format(date, 'yyyy-MM-dd') : '')}
                                placeholder="Select date"
                                dateFormat="yyyy-MM-dd"
                            />
                        )}
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
                {supportsDateRange && (
                    <div className="space-y-2">
                        {isFirst && <Label htmlFor={`${id}-dateMode`}>Date Mode</Label>}
                        <ToggleGroup
                            id={`${id}-dateMode`}
                            type="single"
                            variant="outline"
                            size="sm"
                            value={dateMode}
                            onValueChange={handleDateModeChange}
                            className="h-9 w-full"
                            aria-label="Date mode"
                        >
                            <ToggleGroupItem value="single" className="h-9 flex-1 px-2 text-xs">
                                Single date
                            </ToggleGroupItem>
                            <ToggleGroupItem value="range" className="h-9 flex-1 px-2 text-xs">
                                Period
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                )}
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

            {/* Time/Timezone row: shown when a full-precision date is set (not partial YYYY or YYYY-MM) */}
            {((startDate && !isPartialDate(startDate)) || (endDate && !isPartialDate(endDate)) || hasTimeInfo) && (
                <div className="grid grid-cols-1 gap-4 border-l-2 border-muted pl-4 md:grid-cols-[1fr_1fr]">
                    <div className="space-y-2">
                        <Label htmlFor={`${id}-startTime`} className="text-xs text-muted-foreground">
                            {isDateRange ? 'Start Time' : 'Time'} (optional)
                        </Label>
                        <Input
                            id={`${id}-startTime`}
                            type="time"
                            step="1"
                            value={normalizeTimeForInput(startTime)}
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
                                    step="1"
                                    value={normalizeTimeForInput(endTime)}
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
