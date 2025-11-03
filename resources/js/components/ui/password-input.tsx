import { Eye, EyeOff } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Props for the PasswordInput component.
 * Extends all standard HTML input element props.
 */
interface PasswordInputProps extends React.ComponentProps<'input'> {
    /**
     * Aria label for the toggle button when password is hidden.
     * Describes the action that will be performed when clicked (showing the password).
     * @default 'Show text'
     */
    showPasswordLabel?: string;
    /**
     * Aria label for the toggle button when password is visible.
     * Describes the action that will be performed when clicked (hiding the password).
     * @default 'Hide text'
     */
    hidePasswordLabel?: string;
}

const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(
    ({ className, showPasswordLabel = 'Show text', hidePasswordLabel = 'Hide text', autoComplete, ...props }, ref) => {
        const [showPassword, setShowPassword] = React.useState(false);

        const togglePasswordVisibility = () => {
            setShowPassword((prev) => !prev);
        };

        return (
            <div className="relative">
                <Input
                    type={showPassword ? 'text' : 'password'}
                    autoComplete={showPassword ? 'off' : autoComplete}
                    className={cn('pr-10', className)}
                    ref={ref}
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
);

PasswordInput.displayName = 'PasswordInput';

export { PasswordInput };
