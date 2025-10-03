import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { NavFooter } from '@/components/nav-footer';

vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarGroupContent: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarMenu: ({ children }: { children?: React.ReactNode }) => <ul>{children}</ul>,
    SidebarMenuButton: ({ children }: { children?: React.ReactNode }) => <span>{children}</span>,
    SidebarMenuItem: ({ children }: { children?: React.ReactNode }) => <li>{children}</li>,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/icon', () => ({
    Icon: ({ iconNode }: { iconNode: React.ReactNode }) => <span data-testid="icon">{iconNode}</span>,
}));

describe('NavFooter', () => {
    it('renders footer items with links and icons', () => {
        render(
            <NavFooter
                items={[
                    { title: 'Docs', href: '/docs', icon: <svg data-testid="svg" /> },
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

