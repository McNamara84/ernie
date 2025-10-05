import { Label } from '@/components/ui/label';
import {
    Select,
    SelectTrigger,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { type HTMLAttributes } from 'react';

interface Option {
    value: string;
    label: string;
}

interface SelectFieldProps {
    id: string;
    label: string;
    value: string;
    onValueChange: (value: string) => void;
    options: Option[];
    placeholder?: string;
    className?: string;
    hideLabel?: boolean;
    required?: boolean;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    triggerClassName?: string;
}

export function SelectField({
    id,
    label,
    value,
    onValueChange,
    options,
    placeholder = 'Select',
    className,
    hideLabel = false,
    required = false,
    containerProps,
    triggerClassName,
}: SelectFieldProps) {
    const labelId = `${id}-label`;
    const mergedClassName = cn(
        'flex flex-col gap-2',
        containerProps?.className,
        className,
    );

    return (
        <div {...containerProps} className={mergedClassName}>
            <Label
                id={labelId}
                htmlFor={id}
                className={hideLabel ? 'sr-only' : undefined}
            >
                {label}
                {required && (
                    <span aria-hidden="true" className="text-destructive ml-1">
                        *
                    </span>
                )}
            </Label>
            <Select value={value} onValueChange={onValueChange} required={required}>
                <SelectTrigger
                    id={id}
                    aria-label={label}
                    aria-labelledby={labelId}
                    aria-required={required || undefined}
                    className={triggerClassName}
                >
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
