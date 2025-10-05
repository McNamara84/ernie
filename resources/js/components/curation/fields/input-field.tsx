import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type HTMLAttributes, type InputHTMLAttributes } from 'react';

interface InputFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    hideLabel?: boolean;
    className?: string;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    inputClassName?: string;
}

export function InputField({
    id,
    label,
    hideLabel = false,
    type = 'text',
    className,
    required,
    containerProps,
    inputClassName,
    ...props
}: InputFieldProps) {
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
            <Input
                id={id}
                type={type}
                required={required}
                aria-labelledby={labelId}
                className={inputClassName}
                {...props}
            />
        </div>
    );
}

export default InputField;
