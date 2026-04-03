import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Welcome from '@/pages/auth/welcome';

const { routerMock } = vi.hoisted(() => ({
    routerMock: {
        post: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: routerMock,
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children, title, description }: { children: React.ReactNode; title: string; description: string }) => (
        <div data-testid="auth-layout" data-title={title} data-description={description}>
            {children}
        </div>
    ),
}));

vi.mock('@/actions/App/Http/Controllers/Auth/WelcomeController', () => ({
    default: {
        store: {
            url: vi.fn((_args: unknown, options?: { query?: Record<string, string> }) => {
                const base = '/welcome/123';
                if (options?.query) {
                    const params = new URLSearchParams(options.query).toString();
                    return `${base}?${params}`;
                }
                return base;
            }),
        },
    },
}));

beforeEach(() => {
    routerMock.post.mockReset();
});

describe('Welcome', () => {
    const defaultProps = {
        email: 'user@example.com',
        userId: 123,
        signatureParams: {
            expires: '1712345678',
            signature: 'abc123def456',
        },
    };

    it('renders the welcome page', () => {
        render(<Welcome {...defaultProps} />);
        expect(screen.getByTestId('auth-layout')).toBeInTheDocument();
    });

    it('displays the welcome title', () => {
        render(<Welcome {...defaultProps} />);
        const layout = screen.getByTestId('auth-layout');
        expect(layout).toHaveAttribute('data-title', 'Welcome to ERNIE');
    });

    it('displays the description', () => {
        render(<Welcome {...defaultProps} />);
        const layout = screen.getByTestId('auth-layout');
        expect(layout).toHaveAttribute('data-description', 'Set your password to activate your account');
    });

    it('renders email input as readonly', () => {
        render(<Welcome {...defaultProps} />);
        const emailInput = screen.getByLabelText('Email') as HTMLInputElement;
        expect(emailInput).toBeInTheDocument();
        expect(emailInput).toHaveAttribute('readonly');
        expect(emailInput.value).toBe('user@example.com');
    });

    it('renders password input field', () => {
        render(<Welcome {...defaultProps} />);
        expect(screen.getByLabelText('Password')).toBeInTheDocument();
    });

    it('renders password confirmation input field', () => {
        render(<Welcome {...defaultProps} />);
        expect(screen.getByLabelText('Confirm Password')).toBeInTheDocument();
    });

    it('renders submit button', () => {
        render(<Welcome {...defaultProps} />);
        expect(screen.getByRole('button', { name: /Set Password & Continue/i })).toBeInTheDocument();
    });

    it('has password placeholders', () => {
        render(<Welcome {...defaultProps} />);
        expect(screen.getByPlaceholderText('Enter your new password')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Confirm your password')).toBeInTheDocument();
    });

    it('renders a form element', () => {
        render(<Welcome {...defaultProps} />);
        const button = screen.getByRole('button', { name: /Set Password & Continue/i });
        expect(button.closest('form')).toBeInTheDocument();
    });

    it('includes signature params in the POST request URL', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<Welcome {...defaultProps} />);
        const user = userEvent.setup();

        await user.type(screen.getByPlaceholderText('Enter your new password'), 'Password123!');
        await user.type(screen.getByPlaceholderText('Confirm your password'), 'Password123!');
        await user.click(screen.getByRole('button', { name: /Set Password & Continue/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                expect.stringContaining('expires=1712345678'),
                expect.objectContaining({
                    password: 'Password123!',
                    password_confirmation: 'Password123!',
                }),
                expect.any(Object),
            );
        });
    });

    it('includes signature in the POST request URL', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<Welcome {...defaultProps} />);
        const user = userEvent.setup();

        await user.type(screen.getByPlaceholderText('Enter your new password'), 'Password123!');
        await user.type(screen.getByPlaceholderText('Confirm your password'), 'Password123!');
        await user.click(screen.getByRole('button', { name: /Set Password & Continue/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                expect.stringContaining('signature=abc123def456'),
                expect.any(Object),
                expect.any(Object),
            );
        });
    });

    it('disables submit button while processing', async () => {
        routerMock.post.mockImplementation(() => {});
        render(<Welcome {...defaultProps} />);
        const user = userEvent.setup();

        await user.type(screen.getByPlaceholderText('Enter your new password'), 'Password123!');
        await user.type(screen.getByPlaceholderText('Confirm your password'), 'Password123!');
        await user.click(screen.getByRole('button', { name: /Set Password & Continue/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Set Password & Continue/i })).toBeDisabled();
        });
    });

    it('shows validation error when passwords do not match', async () => {
        render(<Welcome {...defaultProps} />);
        const user = userEvent.setup();

        await user.type(screen.getByPlaceholderText('Enter your new password'), 'Password123!');
        await user.type(screen.getByPlaceholderText('Confirm your password'), 'DifferentPassword!');
        await user.click(screen.getByRole('button', { name: /Set Password & Continue/i }));

        await waitFor(() => {
            expect(screen.getByText(/passwords do not match/i)).toBeInTheDocument();
        });
    });

    it('shows validation error when password is too short', async () => {
        render(<Welcome {...defaultProps} />);
        const user = userEvent.setup();

        await user.type(screen.getByPlaceholderText('Enter your new password'), 'short');
        await user.type(screen.getByPlaceholderText('Confirm your password'), 'short');
        await user.click(screen.getByRole('button', { name: /Set Password & Continue/i }));

        await waitFor(() => {
            expect(screen.getByText(/at least 8 characters/i)).toBeInTheDocument();
        });
    });

    it('shows server errors returned via onError', async () => {
        routerMock.post.mockImplementation(
            (_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
                options?.onError?.({ password: 'The password is too common.' });
                options?.onFinish?.();
            },
        );
        render(<Welcome {...defaultProps} />);
        const user = userEvent.setup();

        await user.type(screen.getByPlaceholderText('Enter your new password'), 'Password123!');
        await user.type(screen.getByPlaceholderText('Confirm your password'), 'Password123!');
        await user.click(screen.getByRole('button', { name: /Set Password & Continue/i }));

        await waitFor(() => {
            expect(screen.getByText('The password is too common.')).toBeInTheDocument();
        });
    });
});
