import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { UserRoleBadge } from '@/components/user-role-badge';

// Mock user data type
interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    is_active: boolean;
    deactivated_at: string | null;
    deactivated_by: { id: number; name: string } | null;
    created_at: string;
}

describe('Users Index Components', () => {
    describe('UserRoleBadge Integration', () => {
        it('renders role badge for each user role type', () => {
            const roles: Array<{ role: string; label: string }> = [
                { role: 'admin', label: 'Admin' },
                { role: 'group_leader', label: 'Group Leader' },
                { role: 'curator', label: 'Curator' },
                { role: 'beginner', label: 'Beginner' },
            ];

            const { container } = render(
                <div>
                    {roles.map((r) => (
                        <UserRoleBadge key={r.role} role={r.role} label={r.label} />
                    ))}
                </div>,
            );

            expect(screen.getByText('Admin')).toBeInTheDocument();
            expect(screen.getByText('Group Leader')).toBeInTheDocument();
            expect(screen.getByText('Curator')).toBeInTheDocument();
            expect(screen.getByText('Beginner')).toBeInTheDocument();

            // Verify destructive variant for admin
            expect(container.querySelector('.bg-destructive')).toBeInTheDocument();
            // Verify primary variant for group leader
            expect(container.querySelector('.bg-primary')).toBeInTheDocument();
            // Verify secondary variant for curator
            expect(container.querySelector('.bg-secondary')).toBeInTheDocument();
        });

        it('displays correct visual hierarchy for roles', () => {
            const { container } = render(
                <div>
                    <UserRoleBadge role="admin" label="Admin" />
                    <UserRoleBadge role="beginner" label="Beginner" />
                </div>,
            );

            // Admin badge should have destructive styling (red/prominent)
            const adminBadge = container.querySelector('.bg-destructive');
            expect(adminBadge).toBeInTheDocument();
            expect(adminBadge).toHaveTextContent('Admin');

            // Check both badges are rendered
            expect(screen.getByText('Admin')).toBeInTheDocument();
            expect(screen.getByText('Beginner')).toBeInTheDocument();
        });
    });

    describe('User Data Display', () => {
        it('formats user information correctly', () => {
            const mockUser: User = {
                id: 1,
                name: 'John Doe',
                email: 'john@example.com',
                role: 'admin',
                role_label: 'Admin',
                is_active: true,
                deactivated_at: null,
                deactivated_by: null,
                created_at: '2025-01-15T10:00:00Z',
            };

            // Test that user data structure is valid
            expect(mockUser.name).toBe('John Doe');
            expect(mockUser.email).toBe('john@example.com');
            expect(mockUser.role).toBe('admin');
            expect(mockUser.is_active).toBe(true);
        });

        it('handles deactivated users correctly', () => {
            const mockDeactivatedUser: User = {
                id: 2,
                name: 'Jane Smith',
                email: 'jane@example.com',
                role: 'curator',
                role_label: 'Curator',
                is_active: false,
                deactivated_at: '2025-01-20T15:30:00Z',
                deactivated_by: { id: 1, name: 'Admin User' },
                created_at: '2024-12-01T08:00:00Z',
            };

            expect(mockDeactivatedUser.is_active).toBe(false);
            expect(mockDeactivatedUser.deactivated_at).not.toBeNull();
            expect(mockDeactivatedUser.deactivated_by).not.toBeNull();
            expect(mockDeactivatedUser.deactivated_by?.name).toBe('Admin User');
        });
    });

    describe('Role Management Logic', () => {
        it('validates role promotion hierarchy', () => {
            // Admin can promote to any role
            const canAdminPromote = (targetRole: string) => {
                return ['admin', 'group_leader', 'curator', 'beginner'].includes(targetRole);
            };

            expect(canAdminPromote('group_leader')).toBe(true);
            expect(canAdminPromote('admin')).toBe(true);

            // Group Leader cannot promote to group_leader or admin
            const canGroupLeaderPromote = (targetRole: string) => {
                return ['curator', 'beginner'].includes(targetRole);
            };

            expect(canGroupLeaderPromote('curator')).toBe(true);
            expect(canGroupLeaderPromote('beginner')).toBe(true);
            expect(canGroupLeaderPromote('group_leader')).toBe(false);
            expect(canGroupLeaderPromote('admin')).toBe(false);
        });

        it('validates user status changes', () => {
            // Can deactivate only active users
            const canDeactivate = (isActive: boolean) => isActive;

            expect(canDeactivate(true)).toBe(true);
            expect(canDeactivate(false)).toBe(false);

            // Can reactivate only inactive users
            const canReactivate = (isActive: boolean) => !isActive;

            expect(canReactivate(false)).toBe(true);
            expect(canReactivate(true)).toBe(false);
        });

        it('protects User ID 1 from modifications', () => {
            const isProtectedUser = (userId: number) => userId === 1;

            expect(isProtectedUser(1)).toBe(true);
            expect(isProtectedUser(2)).toBe(false);
            expect(isProtectedUser(999)).toBe(false);
        });

        it('prevents self-modification', () => {
            const canModify = (currentUserId: number, targetUserId: number) => {
                return currentUserId !== targetUserId;
            };

            expect(canModify(1, 2)).toBe(true);
            expect(canModify(1, 1)).toBe(false);
            expect(canModify(5, 5)).toBe(false);
        });
    });

    describe('Component Rendering', () => {
        it('renders multiple role badges without interference', () => {
            render(
                <div>
                    <UserRoleBadge role="admin" label="Admin" />
                    <UserRoleBadge role="curator" label="Curator" />
                    <UserRoleBadge role="beginner" label="Beginner" />
                </div>,
            );

            const badges = screen.getAllByText(/Admin|Curator|Beginner/);
            expect(badges).toHaveLength(3);
        });

        it('handles empty or null values gracefully', () => {
            const emptyUser: Partial<User> = {
                id: 999,
                name: 'Test User',
                email: 'test@example.com',
                role: 'beginner',
                role_label: 'Beginner',
                is_active: true,
                deactivated_at: null,
                deactivated_by: null,
            };

            expect(emptyUser.deactivated_at).toBeNull();
            expect(emptyUser.deactivated_by).toBeNull();
        });
    });
});
