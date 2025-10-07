import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect,it } from 'vitest';

import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

describe('ToggleGroup', () => {
    it('passes variant and size to items', () => {
        render(
            <ToggleGroup variant="outline" size="sm" type="single">
                <ToggleGroupItem value="a">A</ToggleGroupItem>
            </ToggleGroup>,
        );
        const item = screen.getByRole('radio', { name: 'A' });
        expect(item).toHaveAttribute('data-variant', 'outline');
        expect(item).toHaveAttribute('data-size', 'sm');
    });

    it('toggles item state when clicked', async () => {
        render(
            <ToggleGroup type="single">
                <ToggleGroupItem value="a">A</ToggleGroupItem>
                <ToggleGroupItem value="b">B</ToggleGroupItem>
            </ToggleGroup>,
        );
        const user = userEvent.setup();
        const first = screen.getByRole('radio', { name: 'A' });
        expect(first).toHaveAttribute('data-state', 'off');
        await user.click(first);
        expect(first).toHaveAttribute('data-state', 'on');
        await user.click(first);
        expect(first).toHaveAttribute('data-state', 'off');
    });
});

