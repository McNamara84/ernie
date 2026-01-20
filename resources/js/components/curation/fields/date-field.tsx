import { format, parseISO } from 'date-fns';
import { Minus, Plus } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
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
    options: Option[];
    dateTypeDescription?: string;
    onStartDateChange: (value: string) => void;
    onEndDateChange: (value: string) => void;
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
    options,
    dateTypeDescription,
    onStartDateChange,
    onEndDateChange,
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

    // Track previous dateType to detect actual transitions from 'valid' to non-'valid'
    const prevDateTypeRef = useRef<string>(dateType);

    // Clear endDate when switching away from 'valid' date type to prevent stale data
    useEffect(() => {
        const prevDateType = prevDateTypeRef.current;

        // Only clear endDate when transitioning FROM 'valid' TO a non-'valid' type
        // This prevents race conditions and unnecessary calls when endDate is already empty
        if (prevDateType === 'valid' && dateType !== 'valid' && endDate) {
            onEndDateChange('');
        }

        // Update the ref for the next render
        prevDateTypeRef.current = dateType;
    }, [dateType, endDate, onEndDateChange]);

    return (
        <div className={cn('grid gap-4', isDateRange ? 'md:grid-cols-[1fr_1fr_180px_40px]' : 'md:grid-cols-[1fr_180px_40px]', className)}>
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
    );
}

export default DateField;
