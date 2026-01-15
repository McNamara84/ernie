import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

describe('Popover', () => {
    it('renders the trigger button', () => {
        render(
            <Popover>
                <PopoverTrigger>Open Popover</PopoverTrigger>
                <PopoverContent>Popover content</PopoverContent>
            </Popover>
        );

        expect(screen.getByText('Open Popover')).toBeInTheDocument();
    });

    it('renders with custom className on trigger', () => {
        render(
            <Popover>
                <PopoverTrigger className="custom-trigger">Trigger</PopoverTrigger>
                <PopoverContent>Content</PopoverContent>
            </Popover>
        );

        expect(screen.getByText('Trigger')).toHaveClass('custom-trigger');
    });

    it('renders PopoverAnchor element', () => {
        render(
            <Popover>
                <PopoverAnchor data-testid="anchor">Anchor</PopoverAnchor>
                <PopoverTrigger>Trigger</PopoverTrigger>
                <PopoverContent>Content</PopoverContent>
            </Popover>
        );

        expect(screen.getByTestId('anchor')).toBeInTheDocument();
    });

    it('does not show content by default (closed state)', () => {
        render(
            <Popover>
                <PopoverTrigger>Trigger</PopoverTrigger>
                <PopoverContent>Hidden content</PopoverContent>
            </Popover>
        );

        // Content should not be in the document when popover is closed
        expect(screen.queryByText('Hidden content')).not.toBeInTheDocument();
    });

    it('renders trigger with data attributes', () => {
        render(
            <Popover>
                <PopoverTrigger data-testid="popover-trigger">Click me</PopoverTrigger>
                <PopoverContent>Popover body</PopoverContent>
            </Popover>
        );

        const trigger = screen.getByTestId('popover-trigger');
        expect(trigger).toHaveAttribute('data-state', 'closed');
    });
});
