import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { UserMenuContent } from '@/components/user-menu-content';
import type { User } from '@/types';

const { mockCleanup, flushAll } = vi.hoisted(() => ({
    mockCleanup: vi.fn(),
    flushAll: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, onClick }: { children?: ReactNode; href: string; onClick?: () => void }) => (
        <a
            href={href}
            onClick={(e) => {
                e.preventDefault();
                onClick?.();
            }}
        >
            {children}
        </a>
    ),
    router: {
        flushAll,
    },
}));

vi.mock('@/hooks/use-mobile-navigation', () => ({
    useMobileNavigation: () => mockCleanup,
}));

vi.mock('@/routes', () => ({
    logout: () => '/logout',
    about: () => '/about',
    legalNotice: () => '/legal-notice',
}));

vi.mock('@/routes/profile', () => ({
    edit: () => '/profile/edit',
}));

vi.mock('@/components/user-info', () => ({
    UserInfo: ({ user, showEmail }: { user: { name: string; email: string }; showEmail?: boolean }) => (
        <div>
            <span>{user.name}</span>
            {showEmail && <span>{user.email}</span>}
        </div>
    ),
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenuGroup: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    DropdownMenuItem: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    DropdownMenuLabel: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    DropdownMenuSeparator: () => <hr />,
}));

describe('UserMenuContent', () => {
    beforeEach(() => {
        mockCleanup.mockClear();
        flushAll.mockClear();
    });

    it('renders user info and menu links', () => {
        const currentUser: User = {
            id: 1,
            name: 'Jane',
            email: 'jane@example.com',
            font_size_preference: 'regular',
            email_verified_at: null,
            created_at: '2025-01-01T00:00:00Z',
            updated_at: '2025-01-01T00:00:00Z',
        };

        render(<UserMenuContent user={currentUser} />);
        expect(screen.getByText('Jane')).toBeInTheDocument();
        expect(screen.getByText('jane@example.com')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /settings/i })).toHaveAttribute('href', '/profile/edit');
        expect(screen.getByRole('link', { name: /log out/i })).toHaveAttribute('href', '/logout');
    });

    it('calls cleanup and flushAll on link interactions', async () => {
        const user = userEvent.setup();
        const currentUser: User = {
            id: 1,
            name: 'Jane',
            email: 'jane@example.com',
            font_size_preference: 'regular',
            email_verified_at: null,
            created_at: '2025-01-01T00:00:00Z',
            updated_at: '2025-01-01T00:00:00Z',
        };

        render(<UserMenuContent user={currentUser} />);

        await user.click(screen.getByRole('link', { name: /settings/i }));
        expect(mockCleanup).toHaveBeenCalledTimes(1);

        await user.click(screen.getByRole('link', { name: /log out/i }));
        expect(mockCleanup).toHaveBeenCalledTimes(2);
        expect(flushAll).toHaveBeenCalledTimes(1);
    });
});

