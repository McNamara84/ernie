import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type InputHTMLAttributes } from 'react';

interface InputFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    className?: string;
}

export function InputField({ id, label, type = 'text', className, ...props }: InputFieldProps) {
    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type={type} {...props} />
        </div>
    );
}

export default InputField;
