import { Type } from 'lucide-react';
import { HTMLAttributes } from 'react';

import { useFontSize } from '@/hooks/use-font-size';
import { cn } from '@/lib/utils';
import { type FontSize } from '@/types';

export default function FontSizeToggle({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { fontSize, updateFontSize } = useFontSize();

    const options: { value: FontSize; label: string; description: string }[] = [
        { value: 'regular', label: 'Regular', description: 'Standard font size' },
        { value: 'large', label: 'Large', description: 'Increased font size' },
    ];

    return (
        <div
            className={cn('inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800', className)}
            role="group"
            aria-label="Font size options"
            {...props}
        >
            {options.map(({ value, label, description }) => (
                // Native button used intentionally - custom tab styling incompatible with shadcn Button
                <button
                    key={value}
                    onClick={() => updateFontSize(value)}
                    className={cn(
                        'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                        fontSize === value
                            ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                    aria-label={`Set font size to ${label.toLowerCase()}: ${description}`}
                >
                    <Type className={cn('-ml-1', value === 'regular' ? 'h-4 w-4' : 'h-5 w-5')} />
                    <span className="ml-1.5 text-sm">{label}</span>
                </button>
            ))}
        </div>
    );
}
