import { Eye, EyeOff } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

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
        <div data-slot="password-input" className="relative">
            <Input
                type={showPassword ? 'text' : 'password'}
                autoComplete={showPassword ? 'off' : autoComplete}
                className={cn('pr-10', className)}
                {...props}
            />
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="absolute right-0 top-0 h-full px-3 py-2 hover:bg-transparent"
                onClick={togglePasswordVisibility}
                aria-label={showPassword ? hidePasswordLabel : showPasswordLabel}
            >
                {showPassword ? (
                    <EyeOff className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                ) : (
                    <Eye className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                )}
            </Button>
        </div>
    );
}

export { PasswordInput };
