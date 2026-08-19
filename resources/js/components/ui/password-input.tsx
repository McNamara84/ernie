import { Eye, EyeOff } from 'lucide-react';
import * as React from 'react';

import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';

interface PasswordInputProps extends React.ComponentProps<'input'> {
    showPasswordLabel?: string;
    hidePasswordLabel?: string;
}

function PasswordInput({
    className,
    showPasswordLabel = 'Show text',
    hidePasswordLabel = 'Hide text',
    autoComplete,
    ...props
}: PasswordInputProps) {
    const [showPassword, setShowPassword] = React.useState(false);

    const togglePasswordVisibility = () => {
        setShowPassword((prev) => !prev);
    };

    return (
        <InputGroup data-password-input="">
            <InputGroupInput
                type={showPassword ? 'text' : 'password'}
                autoComplete={showPassword ? 'off' : autoComplete}
                className={className}
                {...props}
            />
            <InputGroupAddon align="inline-end">
                <InputGroupButton
                    size="icon-xs"
                    onClick={togglePasswordVisibility}
                    aria-label={showPassword ? hidePasswordLabel : showPasswordLabel}
                >
                    {showPassword ? (
                        <EyeOff className="size-4 text-muted-foreground" aria-hidden="true" />
                    ) : (
                        <Eye className="size-4 text-muted-foreground" aria-hidden="true" />
                    )}
                </InputGroupButton>
            </InputGroupAddon>
        </InputGroup>
    );
}

export { PasswordInput };
