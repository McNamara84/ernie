import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AuthLayout from '../auth-layout';

const AuthLayoutTemplateMock = vi.hoisted(() =>
    vi.fn(({ title, description, children }: any) => (
        <div>
            <h1>{title}</h1>
            <p>{description}</p>
            <div data-testid="content">{children}</div>
        </div>
    )),
);

vi.mock('@/layouts/auth/auth-simple-layout', () => ({
    default: AuthLayoutTemplateMock,
}));

describe('AuthLayout', () => {
    it('renders title, description and children', () => {
        render(
            <AuthLayout title="Sign in" description="Please sign in">
                <form>form</form>
            </AuthLayout>,
        );
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sign in');
        expect(screen.getByText('Please sign in')).toBeInTheDocument();
        expect(screen.getByTestId('content')).toHaveTextContent('form');
        const callProps = AuthLayoutTemplateMock.mock.calls[0][0];
        expect(callProps.title).toBe('Sign in');
        expect(callProps.description).toBe('Please sign in');
    });
});

