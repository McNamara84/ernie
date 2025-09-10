import { Label } from '@/components/ui/label';
import {
    Select,
    SelectTrigger,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

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
}: SelectFieldProps) {
    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <Label htmlFor={id} className={hideLabel ? 'sr-only' : undefined}>
                {label}
            </Label>
            <Select value={value} onValueChange={onValueChange}>
                <SelectTrigger id={id}>
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
