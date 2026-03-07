import { Loader2, type LucideProps } from 'lucide-react';

import { cn } from '@/lib/utils';

type SpinnerSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

interface SpinnerProps extends Omit<LucideProps, 'ref'> {
    /** Size preset for the spinner */
    size?: SpinnerSize;
}

const sizeMap: Record<SpinnerSize, string> = {
    xs: 'h-3 w-3',
    sm: 'h-4 w-4',
    md: 'h-5 w-5',
    lg: 'h-6 w-6',
    xl: 'h-8 w-8',
};

function Spinner({ className, size = 'md', ...props }: SpinnerProps) {
    return <Loader2 data-slot="spinner" className={cn('animate-spin', sizeMap[size], className)} aria-hidden="true" {...props} />;
}

export { Spinner, type SpinnerProps, type SpinnerSize };
