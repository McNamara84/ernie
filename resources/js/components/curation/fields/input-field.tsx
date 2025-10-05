import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type InputHTMLAttributes } from 'react';

interface InputFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    hideLabel?: boolean;
    className?: string;
}

export function InputField({
    id,
    label,
    hideLabel = false,
    type = 'text',
    className,
    required,
    ...props
}: InputFieldProps) {
    const labelId = `${id}-label`;

    return (
        <div className={cn('flex flex-col gap-2', className)}>
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
            <Input
                id={id}
                type={type}
                required={required}
                aria-label={label}
                aria-labelledby={labelId}
                {...props}
            />
        </div>
    );
}

export default InputField;
