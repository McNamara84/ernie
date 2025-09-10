import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import { Accordion, AccordionItem, AccordionTrigger, AccordionContent } from '../accordion';

describe('Accordion', () => {
    it('toggles content when trigger is clicked', async () => {
        render(
            <Accordion type="single" collapsible>
                <AccordionItem value="item-1">
                    <AccordionTrigger>Toggle</AccordionTrigger>
                    <AccordionContent data-testid="content">
                        Hidden
                    </AccordionContent>
                </AccordionItem>
            </Accordion>,
        );
        const trigger = screen.getByRole('button', { name: 'Toggle' });
        const content = screen.getByTestId('content');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(content).toHaveAttribute('hidden');
        await userEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(content).not.toHaveAttribute('hidden');
    });
});
