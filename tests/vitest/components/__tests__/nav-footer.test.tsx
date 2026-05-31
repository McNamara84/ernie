import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { Book, type LucideProps } from 'lucide-react';
import { type ComponentType,forwardRef } from 'react';
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
    Link: ({ children, href, prefetch, ...props }: { children?: React.ReactNode; href: string; prefetch?: boolean } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => {
        void prefetch;

        return (
            <a href={href} {...props}>
                {children}
            </a>
        );
    },
}));

vi.mock('@/components/icon', () => ({
    Icon: ({ iconNode: IconComponent }: { iconNode: ComponentType<LucideProps> }) => (
        <span data-testid="icon"><IconComponent /></span>
    ),
}));

// Mock LucideIcon for testing - use forwardRef to match LucideIcon type signature
const MockIcon = forwardRef<SVGSVGElement, LucideProps>((_props, ref) => (
    <svg ref={ref} data-testid="mock-icon" />
));
MockIcon.displayName = 'MockIcon';

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

    it('passes data-tour attributes to footer links', () => {
        render(<NavFooter items={[{ title: 'Docs', href: '/docs', icon: Book, tourId: 'sidebar-documentation' }]} />);

        expect(screen.getByRole('link', { name: /docs/i })).toHaveAttribute('data-tour', 'sidebar-documentation');
    });

    it('supports footer items that open in a new tab', () => {
        render(<NavFooter items={[{ title: 'Portal', href: '/portal', icon: Book, openInNewTab: true }]} />);

        const link = screen.getByRole('link', { name: /portal/i });
        expect(link).toHaveAttribute('href', '/portal');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });
});

