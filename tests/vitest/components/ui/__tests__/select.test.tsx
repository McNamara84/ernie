import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, expect,it } from 'vitest';

import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

describe('Select', () => {
    beforeAll(() => {
        // Polyfill pointer capture methods required by Radix Select
        Element.prototype.hasPointerCapture = () => false;
        Element.prototype.setPointerCapture = () => {};
        Element.prototype.releasePointerCapture = () => {};
        Element.prototype.scrollIntoView = () => {};
    });
    it('renders groups and items when open', () => {
        render(
            <Select defaultOpen>
                <SelectTrigger data-testid="trigger">
                    <SelectValue placeholder="Select" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectLabel>Fruits</SelectLabel>
                        <SelectItem value="apple">Apple</SelectItem>
                    </SelectGroup>
                    <SelectSeparator />
                    <SelectItem value="banana">Banana</SelectItem>
                </SelectContent>
            </Select>,
        );
        expect(screen.getByText('Fruits')).toBeInTheDocument();
        expect(screen.getByText('Apple')).toBeInTheDocument();
        expect(screen.getByText('Banana')).toBeInTheDocument();
    });

    it('updates trigger text when selecting an item', async () => {
        render(
            <Select defaultValue="apple">
                <SelectTrigger data-testid="trigger">
                    <SelectValue placeholder="Select" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="apple">Apple</SelectItem>
                    <SelectItem value="banana">Banana</SelectItem>
                </SelectContent>
            </Select>,
        );
        const trigger = screen.getByTestId('trigger');
        expect(trigger).toHaveTextContent('Apple');
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await user.click(trigger);
        await user.click(await screen.findByText('Banana'));
        expect(trigger).toHaveTextContent('Banana');
    });
});

