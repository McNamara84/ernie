import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect,it } from 'vitest';

import { Tooltip, TooltipContent,TooltipTrigger } from '@/components/ui/tooltip';

describe('Tooltip', () => {
    it('renders content when open', () => {
        render(
            <Tooltip defaultOpen>
                <TooltipTrigger>Hover me</TooltipTrigger>
                <TooltipContent>Tooltip text</TooltipContent>
            </Tooltip>,
        );
        const content = screen.getByText('Tooltip text', {
            selector: '[data-slot="tooltip-content"]',
        });
        expect(content).toBeInTheDocument();
    });
});

