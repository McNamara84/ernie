import { Minus, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { normalizeTimeForInput, TIMEZONE_OPTIONS } from '@/lib/date-utils';
import { type EditorDateLocale, parseEditorDate } from '@/lib/editor-date';
import { cn } from '@/lib/utils';

import { type DateMode, isDateRangeCapable } from '../utils/date-rules';
import { EditorDateInput } from './editor-date-input';
import { SelectField } from './select-field';

function isFullDate(date: string | null, locale: EditorDateLocale): boolean {
    return Boolean(date && parseEditorDate(date, locale)?.precision === 'day');
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
    onStartDateChange: (value: string, committed?: boolean) => void;
    onEditingChange?: (id: string, editing: boolean) => void;
    locale?: EditorDateLocale;
    onEndDateChange: (value: string, committed?: boolean) => void;
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
    onEditingChange,
    locale = 'iso',
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
                    {isFirst && <Label htmlFor={`${id}-${isDateRange ? 'startDate' : 'date'}`}>{isDateRange ? 'Start Date' : 'Date'}</Label>}
                    <EditorDateInput
                        id={`${id}-${isDateRange ? 'startDate' : 'date'}`}
                        value={startDate}
                        onChange={onStartDateChange}
                        onEditingChange={onEditingChange}
                        locale={locale}
                        calendarLabel={isDateRange ? 'Choose start date' : 'Choose date'}
                        clearLabel={isDateRange ? 'Clear start date' : 'Clear date'}
                    />
                </div>
                {isDateRange && (
                    <div className="space-y-2">
                        {isFirst && <Label htmlFor={`${id}-endDate`}>End Date</Label>}
                        <EditorDateInput
                            id={`${id}-endDate`}
                            value={endDate}
                            onChange={onEndDateChange}
                            onEditingChange={onEditingChange}
                            locale={locale}
                            calendarLabel="Choose end date"
                            clearLabel="Clear end date"
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
            {(isFullDate(startDate, locale) || isFullDate(endDate, locale) || hasTimeInfo) && (
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
