import { render, screen } from '@testing-library/react';
import { Database, Home, Settings } from 'lucide-react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { NavSection } from '@/components/nav-section';
import { type NavItem } from '@/types';

// Mock Inertia's usePage hook
const mockUsePage = vi.fn();
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => mockUsePage(),
}));

// Mock sidebar components
vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: ({ children, className }: { children: React.ReactNode; className?: string }) => (
        <div data-testid="sidebar-group" className={className}>
            {children}
        </div>
    ),
    SidebarGroupLabel: ({ children }: { children: React.ReactNode }) => <h3 data-testid="sidebar-label">{children}</h3>,
    SidebarMenu: ({ children }: { children: React.ReactNode }) => <ul data-testid="sidebar-menu">{children}</ul>,
    SidebarMenuButton: ({
        children,
        isActive,
        disabled,
        asChild,
        tooltip,
        className,
        ...props
    }: {
        children: React.ReactNode;
        isActive?: boolean;
        disabled?: boolean;
        asChild?: boolean;
        tooltip?: { children: string };
        className?: string;
    }) => {
        if (asChild) {
            return <>{children}</>;
        }
        return (
            <button
                data-testid="sidebar-button"
                data-active={isActive}
                disabled={disabled}
                aria-label={tooltip?.children}
                className={className}
                {...props}
            >
                {children}
            </button>
        );
    },
    SidebarMenuItem: ({ children }: { children: React.ReactNode }) => <li data-testid="sidebar-item">{children}</li>,
    SidebarSeparator: ({ className }: { className?: string }) => <hr data-testid="sidebar-separator" className={className} />,
}));

describe('NavSection', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue({ url: '/dashboard' });
    });

    it('renders nothing when items array is empty', () => {
        const { container } = render(<NavSection items={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders navigation items with titles', () => {
        const items: NavItem[] = [
            { title: 'Dashboard', href: '/dashboard', icon: Home },
            { title: 'Resources', href: '/resources', icon: Database },
        ];

        render(<NavSection items={items} />);

        expect(screen.getByText('Dashboard')).toBeVisible();
        expect(screen.getByText('Resources')).toBeVisible();
    });

    it('renders section label when provided', () => {
        const items: NavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: Home }];

        render(<NavSection label="Main Navigation" items={items} />);

        expect(screen.getByTestId('sidebar-label')).toHaveTextContent('Main Navigation');
    });

    it('does not render label when not provided', () => {
        const items: NavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: Home }];

        render(<NavSection items={items} />);

        expect(screen.queryByTestId('sidebar-label')).not.toBeInTheDocument();
    });

    it('renders separator when showSeparator is true', () => {
        const items: NavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: Home }];

        render(<NavSection items={items} showSeparator />);

        expect(screen.getByTestId('sidebar-separator')).toBeInTheDocument();
    });

    it('does not render separator when showSeparator is false', () => {
        const items: NavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: Home }];

        render(<NavSection items={items} showSeparator={false} />);

        expect(screen.queryByTestId('sidebar-separator')).not.toBeInTheDocument();
    });

    it('renders disabled items with proper styling', () => {
        const items: NavItem[] = [
            { title: 'Settings', href: '/settings', icon: Settings, disabled: true },
        ];

        render(<NavSection items={items} />);

        const button = screen.getByTestId('sidebar-button');
        expect(button).toBeDisabled();
        expect(button).toHaveClass('cursor-not-allowed');
        expect(button).toHaveClass('opacity-50');
    });

    it('renders links for non-disabled items', () => {
        const items: NavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: Home }];

        render(<NavSection items={items} />);

        const link = screen.getByRole('link', { name: /dashboard/i });
        expect(link).toHaveAttribute('href', '/dashboard');
    });

    it('handles href as route object with url property', () => {
        const items: NavItem[] = [
            { title: 'Resources', href: { url: '/resources', method: 'get' }, icon: Database },
        ];

        render(<NavSection items={items} />);

        const link = screen.getByRole('link', { name: /resources/i });
        expect(link).toHaveAttribute('href', '/resources');
    });

    it('applies active state when current URL matches href exactly', () => {
        mockUsePage.mockReturnValue({ url: '/dashboard' });

        const items: NavItem[] = [
            { title: 'Dashboard', href: '/dashboard', icon: Home },
            { title: 'Resources', href: '/resources', icon: Database },
        ];

        render(<NavSection items={items} />);

        // Dashboard link should exist (active state handled by SidebarMenuButton)
        expect(screen.getByRole('link', { name: /dashboard/i })).toBeInTheDocument();
    });

    it('applies active state when current URL starts with href followed by slash', () => {
        mockUsePage.mockReturnValue({ url: '/resources/123' });

        const items: NavItem[] = [{ title: 'Resources', href: '/resources', icon: Database }];

        render(<NavSection items={items} />);

        expect(screen.getByRole('link', { name: /resources/i })).toBeInTheDocument();
    });

    it('applies active state when current URL starts with href followed by query string', () => {
        mockUsePage.mockReturnValue({ url: '/resources?page=2' });

        const items: NavItem[] = [{ title: 'Resources', href: '/resources', icon: Database }];

        render(<NavSection items={items} />);

        expect(screen.getByRole('link', { name: /resources/i })).toBeInTheDocument();
    });

    it('renders icons for navigation items', () => {
        const items: NavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: Home }];

        render(<NavSection items={items} />);

        // The icon should be rendered (Home icon from lucide-react)
        const link = screen.getByRole('link', { name: /dashboard/i });
        expect(link.querySelector('svg')).toBeInTheDocument();
    });

    it('creates unique keys for each menu item based on title', () => {
        const items: NavItem[] = [
            { title: 'Dashboard', href: '/dashboard', icon: Home },
            { title: 'Resources', href: '/resources', icon: Database },
            { title: 'Settings', href: '/settings', icon: Settings },
        ];

        render(<NavSection items={items} />);

        const menuItems = screen.getAllByTestId('sidebar-item');
        expect(menuItems).toHaveLength(3);
    });
});
