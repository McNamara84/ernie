import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { Book } from 'lucide-react';
import type { ReactNode } from 'react';
import type { ComponentType } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { NavFooter } from '@/components/nav-footer';

vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    SidebarGroupContent: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    SidebarMenu: ({ children }: { children?: ReactNode }) => <ul>{children}</ul>,
    SidebarMenuButton: ({ children }: { children?: ReactNode }) => <span>{children}</span>,
    SidebarMenuItem: ({ children }: { children?: ReactNode }) => <li>{children}</li>,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children?: ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/icon', () => ({
    Icon: ({ iconNode: IconComponent }: { iconNode: ComponentType }) => (
        <span data-testid="icon">
            <IconComponent />
        </span>
    ),
}));

describe('NavFooter', () => {
    it('renders footer items with links and icons', () => {
        render(
            <NavFooter
                items={[
                    { title: 'Docs', href: '/docs', icon: Book },
                    { title: 'GitHub', href: '/github' },
                ]}
            />,
        );

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', '/docs');
        expect(screen.getByTestId('icon')).toBeInTheDocument();
        expect(links[1]).toHaveAttribute('href', '/github');
    });
});

