import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Index from '@/pages/Users/Index';

// Mock dependencies
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => ({
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Admin User',
                    email: 'admin@example.com',
                    role: 'admin',
                },
            },
        },
    }),
    router: {
        patch: vi.fn(),
        post: vi.fn(),
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@/components/add-user-dialog', () => ({
    AddUserDialog: ({ disabled }: { disabled?: boolean }) => (
        <button data-testid="add-user-dialog" disabled={disabled}>
            Add User
        </button>
    ),
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

describe('Users/Index', () => {
    const defaultUsers = [
        {
            id: 1,
            name: 'Admin User',
            email: 'admin@example.com',
            role: 'admin',
            role_label: 'Admin',
            is_active: true,
            deactivated_at: null,
            deactivated_by: null,
            created_at: '2024-01-01T00:00:00Z',
        },
        {
            id: 2,
            name: 'Regular User',
            email: 'user@example.com',
            role: 'curator',
            role_label: 'Curator',
            is_active: true,
            deactivated_at: null,
            deactivated_by: null,
            created_at: '2024-06-15T00:00:00Z',
        },
        {
            id: 3,
            name: 'Deactivated User',
            email: 'inactive@example.com',
            role: 'beginner',
            role_label: 'Beginner',
            is_active: false,
            deactivated_at: '2024-10-01T00:00:00Z',
            deactivated_by: {
                id: 1,
                name: 'Admin User',
            },
            created_at: '2024-05-01T00:00:00Z',
        },
    ];

    const defaultAvailableRoles = [
        { value: 'admin', label: 'Admin' },
        { value: 'group_leader', label: 'Group Leader' },
        { value: 'curator', label: 'Curator' },
        { value: 'beginner', label: 'Beginner' },
    ];

    const defaultProps = {
        users: defaultUsers,
        available_roles: defaultAvailableRoles,
        can_promote_to_group_leader: true,
        can_create_users: true,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the user management page', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('User Management')).toBeInTheDocument();
    });

    it('displays users table with all users', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByTestId('users-table')).toBeInTheDocument();
        expect(screen.getByText('Admin User')).toBeInTheDocument();
        expect(screen.getByText('Regular User')).toBeInTheDocument();
        expect(screen.getByText('Deactivated User')).toBeInTheDocument();
    });

    it('shows user emails', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('admin@example.com')).toBeInTheDocument();
        expect(screen.getByText('user@example.com')).toBeInTheDocument();
        expect(screen.getByText('inactive@example.com')).toBeInTheDocument();
    });

    it('displays active status for active users', () => {
        render(<Index {...defaultProps} />);

        const activeBadges = screen.getAllByText('Active');
        expect(activeBadges.length).toBeGreaterThan(0);
    });

    it('displays deactivated status for inactive users', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('Deactivated')).toBeInTheDocument();
    });

    it('shows who deactivated a user', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('by Admin User')).toBeInTheDocument();
    });

    it('shows System Admin badge for user ID 1', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('System Admin')).toBeInTheDocument();
    });

    it('shows You badge for current user', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('You')).toBeInTheDocument();
    });

    it('renders Add User dialog when can_create_users is true', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByTestId('add-user-dialog')).toBeInTheDocument();
    });

    it('does not render Add User dialog when can_create_users is false', () => {
        render(<Index {...defaultProps} can_create_users={false} />);

        expect(screen.queryByTestId('add-user-dialog')).not.toBeInTheDocument();
    });

    it('shows Deactivate button for other active users', () => {
        render(<Index {...defaultProps} />);

        // Should have Deactivate button for user 2 (Regular User who is active)
        expect(screen.getByRole('button', { name: /Deactivate/i })).toBeInTheDocument();
    });

    it('shows Reactivate button for deactivated users', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByRole('button', { name: /Reactivate/i })).toBeInTheDocument();
    });

    it('shows password reset button for non-system users', () => {
        render(<Index {...defaultProps} />);

        // Password reset buttons should be present for users other than ID 1
        const passwordButtons = screen.getAllByTitle('Send password reset email');
        expect(passwordButtons.length).toBeGreaterThan(0);
    });

    it('displays formatted registration dates', () => {
        render(<Index {...defaultProps} />);

        // Check for formatted dates
        expect(screen.getByText('Jan 1, 2024')).toBeInTheDocument();
        expect(screen.getByText('Jun 15, 2024')).toBeInTheDocument();
    });

    it('shows alert when no users exist', () => {
        render(<Index {...defaultProps} users={[]} />);

        expect(screen.getByText('No users found in the system.')).toBeInTheDocument();
    });

    it('displays role hierarchy info', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText(/Role Hierarchy:/)).toBeInTheDocument();
        expect(screen.getByText(/Admin > Group Leader > Curator > Beginner/)).toBeInTheDocument();
    });

    it('displays password reset info', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText(/Password Reset:/)).toBeInTheDocument();
    });

    it('calls router.post when deactivate button is clicked', async () => {
        const { router } = await import('@inertiajs/react');
        const user = userEvent.setup();
        render(<Index {...defaultProps} />);

        const deactivateButton = screen.getByRole('button', { name: /Deactivate/i });
        await user.click(deactivateButton);

        await waitFor(() => {
            expect(router.post).toHaveBeenCalledWith(
                '/users/2/deactivate',
                {},
                expect.objectContaining({
                    preserveScroll: true,
                }),
            );
        });
    });

    it('calls router.post when reactivate button is clicked', async () => {
        const { router } = await import('@inertiajs/react');
        const user = userEvent.setup();
        render(<Index {...defaultProps} />);

        const reactivateButton = screen.getByRole('button', { name: /Reactivate/i });
        await user.click(reactivateButton);

        await waitFor(() => {
            expect(router.post).toHaveBeenCalledWith(
                '/users/3/reactivate',
                {},
                expect.objectContaining({
                    preserveScroll: true,
                }),
            );
        });
    });

    it('calls router.post when password reset button is clicked', async () => {
        const { router } = await import('@inertiajs/react');
        const user = userEvent.setup();
        render(<Index {...defaultProps} />);

        // Click the first password reset button (for user 2)
        const passwordButtons = screen.getAllByTitle('Send password reset email');
        await user.click(passwordButtons[0]);

        await waitFor(() => {
            expect(router.post).toHaveBeenCalledWith(
                expect.stringMatching(/\/users\/\d+\/reset-password/),
                {},
                expect.objectContaining({
                    preserveScroll: true,
                }),
            );
        });
    });

    it('shows role select for users other than system admin', () => {
        render(<Index {...defaultProps} />);

        // Should have role selects for non-system-admin users
        const roleSelects = screen.getAllByRole('combobox');
        expect(roleSelects.length).toBeGreaterThan(0);
    });

    it('shows role badges for each user', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('Admin')).toBeInTheDocument();
        expect(screen.getByText('Curator')).toBeInTheDocument();
        expect(screen.getByText('Beginner')).toBeInTheDocument();
    });

    it('renders with empty available_roles gracefully', () => {
        render(<Index {...defaultProps} available_roles={[]} />);

        expect(screen.getByTestId('users-table')).toBeInTheDocument();
    });

    it('hides deactivate button for current user', () => {
        render(<Index {...defaultProps} />);

        // The current user (id=1) should not have a deactivate button
        // Count deactivate buttons - should be 1 for user 2 (user 3 is already deactivated)
        const deactivateButtons = screen.getAllByRole('button', { name: /Deactivate/i });
        expect(deactivateButtons.length).toBe(1);
    });

    it('displays table headers correctly', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('Email')).toBeInTheDocument();
        expect(screen.getByText('Role')).toBeInTheDocument();
        expect(screen.getByText('Status')).toBeInTheDocument();
        expect(screen.getByText('Registered')).toBeInTheDocument();
        expect(screen.getByText('Actions')).toBeInTheDocument();
    });

    it('renders card with correct title', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('User Management')).toBeInTheDocument();
    });
});
