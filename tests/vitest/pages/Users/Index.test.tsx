import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia
const usePageMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => {
        if (title) document.title = title;
        return null;
    },
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    router: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => usePageMock(),
}));

// Mock layout to just render children
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

// Mock sonner
vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

// Mock UI components
vi.mock('@/components/add-user-dialog', () => ({
    AddUserDialog: () => <div data-testid="add-user-dialog" />,
}));

vi.mock('@/components/user-role-badge', () => ({
    UserRoleBadge: ({ role, label }: { role: string; label: string }) => (
        <span data-testid={`role-badge-${role}`}>{label}</span>
    ),
}));

vi.mock('@/components/ui/alert', () => ({
    Alert: ({ children }: { children?: React.ReactNode }) => <div data-testid="alert">{children}</div>,
    AlertDescription: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: ({ children, className }: { children?: React.ReactNode; className?: string }) => (
        <span data-testid="badge" className={className}>
            {children}
        </span>
    ),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...props }: { children?: React.ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) => (
        <button {...props}>{children}</button>
    ),
}));

vi.mock('@/components/ui/card', () => ({
    Card: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    CardContent: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    CardDescription: ({ children }: { children?: React.ReactNode }) => <p>{children}</p>,
    CardHeader: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    CardTitle: ({ children }: { children?: React.ReactNode }) => <h2>{children}</h2>,
}));

vi.mock('@/components/ui/select', () => ({
    Select: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SelectContent: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SelectItem: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SelectTrigger: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SelectValue: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/table', () => ({
    Table: ({ children, ...props }: { children?: React.ReactNode } & React.HTMLAttributes<HTMLTableElement>) => (
        <table {...props}>{children}</table>
    ),
    TableBody: ({ children }: { children?: React.ReactNode }) => <tbody>{children}</tbody>,
    TableCell: ({ children, ...props }: { children?: React.ReactNode } & React.TdHTMLAttributes<HTMLTableCellElement>) => (
        <td {...props}>{children}</td>
    ),
    TableHead: ({ children, ...props }: { children?: React.ReactNode } & React.ThHTMLAttributes<HTMLTableCellElement>) => (
        <th {...props}>{children}</th>
    ),
    TableHeader: ({ children }: { children?: React.ReactNode }) => <thead>{children}</thead>,
    TableRow: ({ children }: { children?: React.ReactNode }) => <tr>{children}</tr>,
}));

vi.mock('lucide-react', () => ({
    KeyRound: () => <svg data-testid="icon-key" />,
    Mail: () => <svg data-testid="icon-mail" />,
    ShieldCheck: () => <svg data-testid="icon-shield-check" />,
    ShieldOff: () => <svg data-testid="icon-shield-off" />,
    UserCog: () => <svg data-testid="icon-user-cog" />,
    Users: () => <svg data-testid="icon-users" />,
}));

// Import component after all mocks
import Index from '@/pages/Users/Index';

const baseUser = {
    id: 1,
    name: 'Admin User',
    email: 'admin@example.com',
    role: 'admin',
    role_label: 'Admin',
    is_active: true,
    deactivated_at: null,
    deactivated_by: null,
    created_at: '2026-01-01T00:00:00.000Z',
    last_seen_at: null,
    is_online: false,
};

const defaultProps = {
    users: [baseUser],
    available_roles: [
        { value: 'admin', label: 'Admin' },
        { value: 'group_leader', label: 'Group Leader' },
        { value: 'curator', label: 'Curator' },
        { value: 'beginner', label: 'Beginner' },
    ],
    can_promote_to_group_leader: true,
    can_create_users: true,
};

describe('Users/Index - Last Seen Column', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePageMock.mockReturnValue({
            props: {
                auth: {
                    user: {
                        id: 1,
                        name: 'Admin User',
                        email: 'admin@example.com',
                        role: 'admin',
                        font_size_preference: 'regular',
                    },
                },
            },
        });
    });

    it('renders the Last Seen column header', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('Last Seen')).toBeInTheDocument();
    });

    it('shows green online indicator for online users', () => {
        const onlineUser = {
            ...baseUser,
            id: 2,
            name: 'Online User',
            last_seen_at: new Date().toISOString(),
            is_online: true,
        };

        render(<Index {...defaultProps} users={[onlineUser]} />);

        const indicator = screen.getByTestId('online-indicator');
        expect(indicator).toBeInTheDocument();
        expect(indicator).toHaveClass('bg-green-500');
    });

    it('shows gray offline indicator for offline users', () => {
        const offlineUser = {
            ...baseUser,
            id: 2,
            name: 'Offline User',
            last_seen_at: '2026-03-01T00:00:00.000Z',
            is_online: false,
        };

        render(<Index {...defaultProps} users={[offlineUser]} />);

        const indicator = screen.getByTestId('offline-indicator');
        expect(indicator).toBeInTheDocument();
        expect(indicator).toHaveClass('bg-gray-400');
    });

    it('shows "Never" for users with null last_seen_at', () => {
        const neverSeenUser = {
            ...baseUser,
            id: 2,
            name: 'Never Seen',
            last_seen_at: null,
            is_online: false,
        };

        render(<Index {...defaultProps} users={[neverSeenUser]} />);

        expect(screen.getByText('Never')).toBeInTheDocument();
    });

    it('shows offline indicator for users with null last_seen_at', () => {
        const neverSeenUser = {
            ...baseUser,
            id: 2,
            name: 'Never Seen',
            last_seen_at: null,
            is_online: false,
        };

        render(<Index {...defaultProps} users={[neverSeenUser]} />);

        const indicator = screen.getByTestId('offline-indicator');
        expect(indicator).toBeInTheDocument();
        expect(indicator).toHaveClass('bg-gray-400');
    });

    it('displays relative time for last seen users', () => {
        // Set a date that's 1 hour ago
        const oneHourAgo = new Date(Date.now() - 60 * 60 * 1000).toISOString();
        const user = {
            ...baseUser,
            id: 2,
            name: 'Recent User',
            last_seen_at: oneHourAgo,
            is_online: false,
        };

        render(<Index {...defaultProps} users={[user]} />);

        // date-fns formatDistanceToNow should show something like "about 1 hour ago"
        expect(screen.getByText(/hour/i)).toBeInTheDocument();
    });

    it('renders correctly with multiple users having different online statuses', () => {
        const users = [
            { ...baseUser, id: 1, name: 'Online User', last_seen_at: new Date().toISOString(), is_online: true },
            { ...baseUser, id: 2, name: 'Offline User', last_seen_at: '2026-03-01T00:00:00.000Z', is_online: false },
            { ...baseUser, id: 3, name: 'Never User', last_seen_at: null, is_online: false },
        ];

        render(<Index {...defaultProps} users={users} />);

        expect(screen.getAllByTestId('online-indicator')).toHaveLength(1);
        expect(screen.getAllByTestId('offline-indicator')).toHaveLength(2);
        expect(screen.getByText('Never')).toBeInTheDocument();
    });

    it('provides accessible "Online" label for screen readers when user is online', () => {
        const onlineUser = {
            ...baseUser,
            id: 2,
            name: 'Online User',
            last_seen_at: new Date().toISOString(),
            is_online: true,
        };

        render(<Index {...defaultProps} users={[onlineUser]} />);

        expect(screen.getByText('Online')).toBeInTheDocument();
        expect(screen.getByText('Online')).toHaveClass('sr-only');
    });

    it('provides accessible "Offline" label for screen readers when user is offline', () => {
        const offlineUser = {
            ...baseUser,
            id: 2,
            name: 'Offline User',
            last_seen_at: '2026-03-01T00:00:00.000Z',
            is_online: false,
        };

        render(<Index {...defaultProps} users={[offlineUser]} />);

        expect(screen.getByText('Offline')).toBeInTheDocument();
        expect(screen.getByText('Offline')).toHaveClass('sr-only');
    });

    it('hides the color dot from assistive technology via aria-hidden', () => {
        const onlineUser = {
            ...baseUser,
            id: 2,
            name: 'Online User',
            last_seen_at: new Date().toISOString(),
            is_online: true,
        };

        render(<Index {...defaultProps} users={[onlineUser]} />);

        const indicator = screen.getByTestId('online-indicator');
        expect(indicator).toHaveAttribute('aria-hidden', 'true');
    });
});
