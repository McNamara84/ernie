import '@testing-library/jest-dom/vitest';

import type { ReactNode } from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { NavUser } from '@/components/nav-user';

type DropdownProps = { side?: string; children?: ReactNode; [key: string]: unknown };
const dropdownCalls: DropdownProps[] = vi.hoisted(() => [] as DropdownProps[]);
const useSidebarMock = vi.hoisted(() => vi.fn());
const useIsMobileMock = vi.hoisted(() => vi.fn());

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    DropdownMenuTrigger: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    DropdownMenuContent: (props: DropdownProps) => {
        dropdownCalls.push(props);
        return (
            <div data-testid="dropdown-content" {...props}>
                {props.children}
            </div>
        );
    },
}));

vi.mock('@/components/ui/sidebar', () => ({
    SidebarMenu: ({ children }: { children?: ReactNode }) => <ul>{children}</ul>,
    SidebarMenuItem: ({ children }: { children?: ReactNode }) => <li>{children}</li>,
    SidebarMenuButton: ({ children, ...props }: { children?: ReactNode }) => <button {...props}>{children}</button>,
    useSidebar: () => useSidebarMock(),
}));

vi.mock('@/components/user-info', () => ({
    UserInfo: ({ user }: { user: { name: string } }) => <div>{user.name}</div>,
}));

vi.mock('@/components/user-menu-content', () => ({
    UserMenuContent: ({ user }: { user: { email: string } }) => <div>{user.email}</div>,
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => useIsMobileMock(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { user: { name: 'John Doe', email: 'john@example.com' } } },
    }),
}));

beforeEach(() => {
    useSidebarMock.mockReturnValue({ state: 'collapsed' });
    useIsMobileMock.mockReturnValue(false);
    dropdownCalls.length = 0;
});

describe('NavUser', () => {
    it('renders user info and uses left side when sidebar collapsed', () => {
        render(<NavUser />);
        expect(screen.getByText('John Doe')).toBeInTheDocument();
        expect(screen.getByText('john@example.com')).toBeInTheDocument();
        expect(dropdownCalls[0].side).toBe('left');
    });

    it('uses bottom side on mobile', () => {
        useIsMobileMock.mockReturnValue(true);
        render(<NavUser />);
        expect(dropdownCalls[0].side).toBe('bottom');
    });
});
