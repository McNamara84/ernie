import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { UserInfo } from '../user-info';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/hooks/use-initials', () => ({
    useInitials: () => () => 'JD',
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: ({ children }: { children?: React.ReactNode }) => <div data-testid="avatar">{children}</div>,
    AvatarImage: ({ ...props }: any) => <img data-testid="avatar-image" {...props} />,
    AvatarFallback: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="avatar-fallback">{children}</div>
    ),
}));

describe('UserInfo', () => {
    const user = { name: 'John Doe', email: 'john@example.com', avatar: '' };

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
