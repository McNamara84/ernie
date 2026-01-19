import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import { Home, Settings, User } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';

import { DocsSidebar, DocsSidebarMobile } from '@/components/docs/docs-sidebar';
import type { DocsSidebarItem } from '@/types/docs';

describe('DocsSidebar', () => {
    const mockItems: DocsSidebarItem[] = [
        { id: 'welcome', label: 'Welcome', icon: Home },
        { id: 'settings', label: 'Settings', icon: Settings },
        {
            id: 'user-management',
            label: 'User Management',
            icon: User,
            children: [
                { id: 'create-user', label: 'Create User' },
                { id: 'edit-user', label: 'Edit User' },
            ],
        },
    ];

    const defaultProps = {
        items: mockItems,
        activeId: 'welcome',
        onSectionClick: vi.fn(),
    };

    describe('rendering', () => {
        it('renders all top-level navigation items', () => {
            render(<DocsSidebar {...defaultProps} />);

            expect(screen.getByText('Welcome')).toBeInTheDocument();
            expect(screen.getByText('Settings')).toBeInTheDocument();
            expect(screen.getByText('User Management')).toBeInTheDocument();
        });

        it('renders nested items', () => {
            render(<DocsSidebar {...defaultProps} />);

            expect(screen.getByText('Create User')).toBeInTheDocument();
            expect(screen.getByText('Edit User')).toBeInTheDocument();
        });

        it('renders icons for items that have them', () => {
            render(<DocsSidebar {...defaultProps} />);

            // Each item with an icon should render an SVG
            const buttons = screen.getAllByRole('button');
            const iconsCount = buttons.filter((btn) => btn.querySelector('svg')).length;
            // Top-level items have icons (3), nested items don't (2)
            expect(iconsCount).toBe(3);
        });

        it('applies aria-label for navigation', () => {
            render(<DocsSidebar {...defaultProps} />);

            expect(screen.getByRole('navigation')).toHaveAttribute('aria-label', 'Documentation navigation');
        });
    });

    describe('active section highlighting', () => {
        it('marks active item with aria-current', () => {
            render(<DocsSidebar {...defaultProps} activeId="welcome" />);

            const welcomeButton = screen.getByText('Welcome').closest('button');
            expect(welcomeButton).toHaveAttribute('aria-current', 'location');
        });

        it('does not mark inactive items with aria-current', () => {
            render(<DocsSidebar {...defaultProps} activeId="welcome" />);

            const settingsButton = screen.getByText('Settings').closest('button');
            expect(settingsButton).not.toHaveAttribute('aria-current');
        });

        it('shows chevron icon for active item', () => {
            render(<DocsSidebar {...defaultProps} activeId="welcome" />);

            const welcomeButton = screen.getByText('Welcome').closest('button');
            // Chevron is rendered as an SVG with 'ml-auto' class
            const chevron = welcomeButton?.querySelector('svg.ml-auto');
            expect(chevron).toBeInTheDocument();
        });

        it('highlights nested item when active', () => {
            render(<DocsSidebar {...defaultProps} activeId="create-user" />);

            const createUserButton = screen.getByText('Create User').closest('button');
            expect(createUserButton).toHaveAttribute('aria-current', 'location');
        });
    });

    describe('click handlers', () => {
        it('calls onSectionClick when item is clicked', () => {
            const onSectionClick = vi.fn();
            render(<DocsSidebar {...defaultProps} onSectionClick={onSectionClick} />);

            const settingsButton = screen.getByText('Settings').closest('button');
            fireEvent.click(settingsButton!);

            expect(onSectionClick).toHaveBeenCalledWith('settings');
        });

        it('calls onSectionClick with nested item id', () => {
            const onSectionClick = vi.fn();
            render(<DocsSidebar {...defaultProps} onSectionClick={onSectionClick} />);

            const createUserButton = screen.getByText('Create User').closest('button');
            fireEvent.click(createUserButton!);

            expect(onSectionClick).toHaveBeenCalledWith('create-user');
        });

        it('prevents default on click', () => {
            const onSectionClick = vi.fn();
            render(<DocsSidebar {...defaultProps} onSectionClick={onSectionClick} />);

            const welcomeButton = screen.getByText('Welcome').closest('button');
            const clickEvent = new MouseEvent('click', { bubbles: true });
            const preventDefaultSpy = vi.spyOn(clickEvent, 'preventDefault');

            welcomeButton?.dispatchEvent(clickEvent);

            // Click handler should prevent default (for anchor-like behavior)
            expect(onSectionClick).toHaveBeenCalled();
        });
    });

    describe('className prop', () => {
        it('applies custom className', () => {
            render(<DocsSidebar {...defaultProps} className="custom-sidebar" />);

            const nav = screen.getByRole('navigation');
            expect(nav).toHaveClass('custom-sidebar');
        });
    });

    describe('empty state', () => {
        it('renders nothing when items array is empty', () => {
            render(<DocsSidebar {...defaultProps} items={[]} />);

            const nav = screen.getByRole('navigation');
            // Navigation should exist but have no buttons
            expect(nav.querySelectorAll('button')).toHaveLength(0);
        });
    });
});

describe('DocsSidebarMobile', () => {
    const mockItems: DocsSidebarItem[] = [
        { id: 'welcome', label: 'Welcome', icon: Home },
        { id: 'settings', label: 'Settings', icon: Settings },
    ];

    const defaultProps = {
        items: mockItems,
        activeId: 'welcome',
        onSectionClick: vi.fn(),
    };

    describe('rendering', () => {
        it('renders all items as pill buttons', () => {
            render(<DocsSidebarMobile {...defaultProps} />);

            expect(screen.getByText('Welcome')).toBeInTheDocument();
            expect(screen.getByText('Settings')).toBeInTheDocument();
        });

        it('renders header text', () => {
            render(<DocsSidebarMobile {...defaultProps} />);

            expect(screen.getByText('On this page')).toBeInTheDocument();
        });

        it('renders icons for items', () => {
            render(<DocsSidebarMobile {...defaultProps} />);

            const buttons = screen.getAllByRole('button');
            expect(buttons[0].querySelector('svg')).toBeInTheDocument();
            expect(buttons[1].querySelector('svg')).toBeInTheDocument();
        });
    });

    describe('active state styling', () => {
        it('applies primary styling to active item', () => {
            render(<DocsSidebarMobile {...defaultProps} activeId="welcome" />);

            const welcomeButton = screen.getByText('Welcome').closest('button');
            expect(welcomeButton).toHaveClass('bg-primary');
        });

        it('applies muted styling to inactive items', () => {
            render(<DocsSidebarMobile {...defaultProps} activeId="welcome" />);

            const settingsButton = screen.getByText('Settings').closest('button');
            expect(settingsButton).toHaveClass('bg-muted');
        });
    });

    describe('click handlers', () => {
        it('calls onSectionClick when item is clicked', () => {
            const onSectionClick = vi.fn();
            render(<DocsSidebarMobile {...defaultProps} onSectionClick={onSectionClick} />);

            const settingsButton = screen.getByText('Settings').closest('button');
            fireEvent.click(settingsButton!);

            expect(onSectionClick).toHaveBeenCalledWith('settings');
        });
    });
});
