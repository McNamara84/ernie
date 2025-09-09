import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
    NavigationMenu,
    NavigationMenuList,
    NavigationMenuItem,
    NavigationMenuTrigger,
    NavigationMenuContent,
} from '../navigation-menu';

describe('NavigationMenu', () => {
    it('renders viewport when an item is opened', async () => {
        const { container } = render(
            <NavigationMenu>
                <NavigationMenuList>
                    <NavigationMenuItem>
                        <NavigationMenuTrigger>Item</NavigationMenuTrigger>
                        <NavigationMenuContent>Content</NavigationMenuContent>
                    </NavigationMenuItem>
                </NavigationMenuList>
            </NavigationMenu>,
        );
        const root = container.querySelector('[data-slot="navigation-menu"]');
        expect(root).toHaveAttribute('data-viewport', 'true');
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await user.click(screen.getByText('Item'));
        expect(document.querySelector('[data-slot="navigation-menu-viewport"]')).toBeInTheDocument();
    });

    it('omits viewport when prop is false', () => {
        const { container } = render(
            <NavigationMenu viewport={false}>
                <NavigationMenuList>
                    <NavigationMenuItem>
                        <NavigationMenuTrigger>Item</NavigationMenuTrigger>
                        <NavigationMenuContent>Content</NavigationMenuContent>
                    </NavigationMenuItem>
                </NavigationMenuList>
            </NavigationMenu>,
        );
        const root = container.querySelector('[data-slot="navigation-menu"]');
        expect(root).toHaveAttribute('data-viewport', 'false');
        expect(container.querySelector('[data-slot="navigation-menu-viewport"]')).not.toBeInTheDocument();
    });
});

