import { Minus, Plus } from 'lucide-react';

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
    date: string;
    dateType: string;
    options: Option[];
    dateTypeDescription?: string;
    onDateChange: (value: string) => void;
    onTypeChange: (value: string) => void;
    onAdd: () => void;
    onRemove: () => void;
    isFirst: boolean;
    canAdd?: boolean;
    className?: string;
}

export function DateField({
    id,
    date,
    dateType,
    options,
    dateTypeDescription,
    onDateChange,
    onTypeChange,
    onAdd,
    onRemove,
    isFirst,
    canAdd = true,
    className,
}: DateFieldProps) {
    return (
        <div className={cn('grid gap-4 md:grid-cols-12', className)}>
            <InputField
                id={`${id}-date`}
                label="Date"
                type="date"
                value={date}
                onChange={(e) => onDateChange(e.target.value)}
                hideLabel={!isFirst}
                className="md:col-span-8"
                required={dateType === 'created'}
            />
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
