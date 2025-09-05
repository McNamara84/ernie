import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/components/ui/avatar', () => ({
    Avatar: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    AvatarImage: ({ src, alt }: { src?: string; alt?: string }) => <img src={src} alt={alt} />,
    AvatarFallback: ({ children }: { children?: React.ReactNode }) => <span>{children}</span>,
}));

import { UserInfo } from '../user-info';

const user = { name: 'Jane Doe', email: 'jane@example.com', avatar: undefined } as const;

describe('UserInfo', () => {
    it('renders user name and initials', () => {
        render(<UserInfo user={user} />);
        expect(screen.getByText('Jane Doe')).toBeInTheDocument();
        expect(screen.getByText('JD')).toBeInTheDocument();
        expect(screen.queryByText('jane@example.com')).toBeNull();
    });

    it('shows email when showEmail is true', () => {
        render(<UserInfo user={user} showEmail />);
        expect(screen.getByText('jane@example.com')).toBeInTheDocument();
    });
});

