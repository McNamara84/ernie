import { Minus, Plus } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import InputField from './input-field';
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
        <div className={cn('grid gap-4 md:grid-cols-12', className)}>
            <InputField
                id={`${id}-${isDateRange ? 'startDate' : 'date'}`}
                label={isDateRange ? 'Start Date' : 'Date'}
                type="date"
                value={startDate ?? ''}
                onChange={(e) => onStartDateChange(e.target.value)}
                hideLabel={!isFirst}
                className={isDateRange ? 'md:col-span-4' : 'md:col-span-8'}
                required={dateType === 'created'}
            />
            {isDateRange && (
                <InputField
                    id={`${id}-endDate`}
                    label="End Date"
                    type="date"
                    value={endDate ?? ''}
                    onChange={(e) => onEndDateChange(e.target.value)}
                    hideLabel={!isFirst}
                    className="md:col-span-4"
                />
            )}
            <div className="md:col-span-3">
                <SelectField
                    id={`${id}-dateType`}
                    label="Date Type"
                    value={dateType}
                    onValueChange={onTypeChange}
                    options={options}
                    hideLabel={!isFirst}
                    required
                />
                {dateTypeDescription && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {dateTypeDescription}
                    </p>
                )}
            </div>
            <div className="flex items-end md:col-span-1">
                {isFirst ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Add date"
                        onClick={onAdd}
                        disabled={!canAdd}
                    >
                        <Plus className="h-4 w-4" />
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Remove date"
                        onClick={onRemove}
                    >
                        <Minus className="h-4 w-4" />
                    </Button>
                )}
            </div>
        </div>
    );
}

export default DateField;
