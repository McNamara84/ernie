import { CheckIcon, MinusIcon } from 'lucide-react';
import { Checkbox as CheckboxPrimitive } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

interface CheckboxProps extends React.ComponentProps<typeof CheckboxPrimitive.Root> {
    indeterminate?: boolean;
}

function Checkbox({ className, indeterminate, checked, ...props }: CheckboxProps) {
    // Determine indeterminate state: either via explicit prop or if checked is already 'indeterminate'
    const isIndeterminate = indeterminate || checked === 'indeterminate';
    // Only show as indeterminate if not fully checked
    const showIndeterminate = isIndeterminate && checked !== true;

    return (
        <CheckboxPrimitive.Root
            data-slot="checkbox"
            data-indeterminate={showIndeterminate || undefined}
            checked={showIndeterminate ? 'indeterminate' : checked}
            className={cn(
                'peer size-4 shrink-0 rounded-[4px] border border-input shadow-xs transition-shadow outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground dark:bg-input/30 dark:aria-invalid:ring-destructive/40 dark:data-[state=checked]:bg-primary dark:data-[state=indeterminate]:bg-primary',
                className,
            )}
            {...props}
        >
            <CheckboxPrimitive.Indicator data-slot="checkbox-indicator" className="grid place-content-center text-current transition-none">
                {showIndeterminate ? <MinusIcon className="size-3.5" /> : <CheckIcon className="size-3.5" />}
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    );
}

export { Checkbox };
