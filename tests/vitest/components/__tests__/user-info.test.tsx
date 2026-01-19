import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { UserInfo } from '@/components/user-info';

import { createMockUser } from '@test-helpers';

vi.mock('@/hooks/use-initials', () => ({
    useInitials: () => () => 'JD',
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: ({ children }: { children?: ReactNode }) => <div data-testid="avatar">{children}</div>,
    AvatarImage: (props: ComponentProps<'img'>) => <img data-testid="avatar-image" {...props} />,
    AvatarFallback: ({ children }: { children?: ReactNode }) => (
        <div data-testid="avatar-fallback">{children}</div>
    ),
}));

describe('UserInfo', () => {
    const user = createMockUser({ name: 'John Doe', email: 'john@example.com', avatar: '' });

    it('shows name, email and initials', () => {
        render(<UserInfo user={user} showEmail />);
        expect(screen.getByText('John Doe')).toBeInTheDocument();
        expect(screen.getByText('john@example.com')).toBeInTheDocument();
        expect(screen.getByTestId('avatar-fallback')).toHaveTextContent('JD');
    });

    it('hides email when showEmail is false', () => {
        render(<UserInfo user={user} />);
        expect(screen.queryByText('john@example.com')).toBeNull();
    });
});
