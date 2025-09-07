import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, it, expect } from 'vitest';
import {
    Select,
    SelectTrigger,
    SelectContent,
    SelectItem,
    SelectValue,
    SelectLabel,
    SelectGroup,
    SelectSeparator,
} from '../select';

describe('Select', () => {
    beforeAll(() => {
        // Polyfill pointer capture methods required by Radix Select
        // @ts-expect-error jsdom lacks this method
        Element.prototype.hasPointerCapture = () => false;
        // @ts-expect-error jsdom lacks this method
        Element.prototype.setPointerCapture = () => {};
        // @ts-expect-error jsdom lacks this method
        Element.prototype.releasePointerCapture = () => {};
        // @ts-expect-error jsdom lacks this method
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

