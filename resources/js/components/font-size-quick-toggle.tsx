import { ALargeSmall } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useFontSize } from '@/hooks/use-font-size';
import { cn } from '@/lib/utils';

/**
 * A compact toggle button for quickly switching between font sizes.
 * Displays in the header for easy access on all pages.
 */
export function FontSizeQuickToggle() {
    const { fontSize, updateFontSize } = useFontSize();

    const toggle = () => {
        updateFontSize(fontSize === 'regular' ? 'large' : 'regular');
    };

    const isLarge = fontSize === 'large';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={toggle}
                    aria-label={`Font size: ${isLarge ? 'Large' : 'Regular'}. Click to switch to ${isLarge ? 'regular' : 'large'} font size.`}
                    className="h-8 w-8"
                >
                    <ALargeSmall
                        className={cn(
                            'transition-transform',
                            isLarge && 'scale-110 text-primary',
                        )}
                    />
                </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p>Font size: {isLarge ? 'Large' : 'Regular'}</p>
            </TooltipContent>
        </Tooltip>
    );
}
