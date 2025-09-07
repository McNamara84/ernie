import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Tooltip, TooltipTrigger, TooltipContent } from '../tooltip';

describe('Tooltip', () => {
    it('renders content when open', () => {
        render(
            <Tooltip defaultOpen>
                <TooltipTrigger>Hover me</TooltipTrigger>
                <TooltipContent>Tooltip text</TooltipContent>
            </Tooltip>,
        );
        const content = document.querySelector('[data-slot="tooltip-content"]');
        expect(content).toBeInTheDocument();
    });
});

