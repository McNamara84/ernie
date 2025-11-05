import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { UserRoleBadge } from '@/components/user-role-badge';

describe('UserRoleBadge', () => {
    describe('Role Display', () => {
        it('renders admin badge with destructive variant', () => {
            const { container } = render(<UserRoleBadge role="admin" label="Admin" />);

            const badge = screen.getByText('Admin');
            expect(badge).toBeInTheDocument();
            
            // Test for variant data attribute instead of CSS classes
            const badgeElement = container.querySelector('[data-slot="badge"]');
            expect(badgeElement).toHaveAttribute('data-variant', 'destructive');
        });

        it('renders group leader badge with default variant', () => {
            const { container } = render(<UserRoleBadge role="group_leader" label="Group Leader" />);

            const badge = screen.getByText('Group Leader');
            expect(badge).toBeInTheDocument();
            
            // Test for variant data attribute instead of CSS classes
            const badgeElement = container.querySelector('[data-slot="badge"]');
            expect(badgeElement).toHaveAttribute('data-variant', 'default');
        });

        it('renders curator badge with secondary variant', () => {
            const { container } = render(<UserRoleBadge role="curator" label="Curator" />);

            const badge = screen.getByText('Curator');
            expect(badge).toBeInTheDocument();
            
            // Test for variant data attribute instead of CSS classes
            const badgeElement = container.querySelector('[data-slot="badge"]');
            expect(badgeElement).toHaveAttribute('data-variant', 'secondary');
        });

        it('renders beginner badge with outline variant', () => {
            const { container } = render(<UserRoleBadge role="beginner" label="Beginner" />);

            const badge = screen.getByText('Beginner');
            expect(badge).toBeInTheDocument();
            
            // Test for variant data attribute instead of CSS classes
            const badgeElement = container.querySelector('[data-slot="badge"]');
            expect(badgeElement).toHaveAttribute('data-variant', 'outline');
        });

        it('falls back to role value when no label provided', () => {
            render(<UserRoleBadge role="admin" />);

            const badge = screen.getByText('admin');
            expect(badge).toBeInTheDocument();
        });
    });

    describe('Visual Styling', () => {
        it('applies correct variant for admin', () => {
            const { container } = render(<UserRoleBadge role="admin" label="Admin" />);
            const badge = container.querySelector('[data-slot="badge"]');

            expect(badge).toBeInTheDocument();
            expect(badge).toHaveAttribute('data-variant', 'destructive');
        });

        it('applies correct variant for group leader', () => {
            const { container } = render(<UserRoleBadge role="group_leader" label="Group Leader" />);
            const badge = container.querySelector('[data-slot="badge"]');

            expect(badge).toBeInTheDocument();
            expect(badge).toHaveAttribute('data-variant', 'default');
        });

        it('applies correct variant for curator', () => {
            const { container } = render(<UserRoleBadge role="curator" label="Curator" />);
            const badge = container.querySelector('[data-slot="badge"]');

            expect(badge).toBeInTheDocument();
            expect(badge).toHaveAttribute('data-variant', 'secondary');
        });

        it('applies outline variant for beginner', () => {
            const { container } = render(<UserRoleBadge role="beginner" label="Beginner" />);
            const badge = container.querySelector('[data-slot="badge"]');

            expect(badge).toBeInTheDocument();
            expect(badge).toHaveAttribute('data-variant', 'outline');
        });
    });

    describe('Accessibility', () => {
        it('has accessible role label for admin', () => {
            render(<UserRoleBadge role="admin" label="Admin" />);
            expect(screen.getByText('Admin')).toBeInTheDocument();
        });

        it('has accessible role label for group leader', () => {
            render(<UserRoleBadge role="group_leader" label="Group Leader" />);
            expect(screen.getByText('Group Leader')).toBeInTheDocument();
        });

        it('has accessible role label for curator', () => {
            render(<UserRoleBadge role="curator" label="Curator" />);
            expect(screen.getByText('Curator')).toBeInTheDocument();
        });

        it('has accessible role label for beginner', () => {
            render(<UserRoleBadge role="beginner" label="Beginner" />);
            expect(screen.getByText('Beginner')).toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        it('handles missing label by showing role value', () => {
            const { container } = render(<UserRoleBadge role="curator" />);
            expect(screen.getByText('curator')).toBeInTheDocument();
            expect(container).toBeInTheDocument();
        });

        it('handles unknown role gracefully', () => {
            render(<UserRoleBadge role="unknown_role" label="Unknown" />);
            const badge = screen.getByText('Unknown');
            expect(badge).toBeInTheDocument();
            // Unknown roles default to outline variant
        });

        it('renders consistently across multiple instances', () => {
            const { rerender } = render(<UserRoleBadge role="admin" label="Admin" />);
            expect(screen.getByText('Admin')).toBeInTheDocument();

            rerender(<UserRoleBadge role="curator" label="Curator" />);
            expect(screen.getByText('Curator')).toBeInTheDocument();
        });
    });
});
