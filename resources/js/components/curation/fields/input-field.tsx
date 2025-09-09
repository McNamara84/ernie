import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type InputHTMLAttributes } from 'react';

interface InputFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
}

export function InputField({ id, label, type = 'text', ...props }: InputFieldProps) {
    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type={type} {...props} />
        </div>
    );
}

export default InputField;
