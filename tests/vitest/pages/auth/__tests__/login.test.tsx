import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ComponentProps, type ReactNode,useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import Login from '@/pages/auth/login';

interface MockFormState {
    errors: Record<string, string>;
    processing: boolean;
}

let initialFormState: MockFormState = { errors: {}, processing: false };
const submitMock = vi.fn<
    [Record<string, FormDataEntryValue>],
    Promise<{ ok: boolean; errors?: Record<string, string> }>
>();
const originalLocation = window.location;

vi.mock('@inertiajs/react', () => {
    return {
        Form: ({
            children,
        }: {
            children?: ReactNode | ((args: { processing: boolean; errors: Record<string, string> }) => ReactNode);
        }) => {
            const [errors, setErrors] = useState(() => ({ ...initialFormState.errors }));
            const [processing, setProcessing] = useState(() => initialFormState.processing);
            const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                setProcessing(true);
                const data = Object.fromEntries(new FormData(e.currentTarget).entries());
                const result = await submitMock(data);
                setProcessing(false);
                if (result.ok) {
                    window.location.assign('/dashboard');
                } else {
                    setErrors(result.errors ?? {});
                }
            };
            return (
                <form data-testid="login-form" onSubmit={handleSubmit}>
                    {typeof children === 'function'
                        ? children({ processing, errors })
                        : children}
                </form>
            );
        },
        Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
        Link: ({ href, children }: { href: string; children?: ReactNode }) => (
            <a href={href}>{children}</a>
        ),
    };
});

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController', () => ({
    default: { store: { form: () => ({}) } },
}));

vi.mock('@/routes/password', () => ({
    request: () => '/forgot-password',
}));

vi.mock('@/components/text-link', () => ({
    default: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/input-error', () => ({
    default: ({ message }: { message?: string }) => (message ? <p>{message}</p> : null),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...props }: ComponentProps<'button'>) => <button {...props}>{children}</button>,
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: (props: ComponentProps<'input'>) => <input type="checkbox" {...props} />,
}));

vi.mock('@/components/ui/input', () => ({
    Input: (props: ComponentProps<'input'>) => <input {...props} />,
}));

vi.mock('@/components/ui/password-input', () => ({
    PasswordInput: (props: ComponentProps<'input'>) => <input type="password" {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, ...props }: ComponentProps<'label'>) => <label {...props}>{children}</label>,
}));

beforeEach(() => {
    initialFormState = { errors: {}, processing: false };
    submitMock.mockReset();
});

const renderLogin = (
    props: Partial<ComponentProps<typeof Login>> = {},
    formState?: Partial<MockFormState>,
) => {
    initialFormState = { errors: {}, processing: false, ...formState };
    return render(<Login canResetPassword={false} {...props} />);
};

afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation });
    vi.restoreAllMocks();
});

describe('Login page', () => {
    it('renders forgot password link when allowed', () => {
        renderLogin({ canResetPassword: true });
        const link = screen.getByRole('link', { name: /forgot password/i });
        expect(link).toHaveAttribute('href', '/forgot-password');
    });

    it('displays status message when provided', () => {
        renderLogin({ status: 'Password reset' });
        expect(screen.getByText('Password reset')).toBeInTheDocument();
    });

    it('renders validation errors', () => {
        renderLogin({}, { errors: { email: 'Invalid email', password: 'Required' } });
        expect(screen.getByText('Invalid email')).toBeInTheDocument();
        expect(screen.getByText('Required')).toBeInTheDocument();
    });

    it('disables the submit button and shows a spinner while processing', () => {
        renderLogin({}, { processing: true });
        const button = screen.getByRole('button', { name: /log in/i });
        expect(button).toBeDisabled();
        expect(screen.getByTestId('loading-spinner')).toBeInTheDocument();
    });

    it('redirects to the dashboard on successful login', async () => {
        submitMock.mockResolvedValue({ ok: true });
        const assignSpy = vi.fn();
        Object.defineProperty(window, 'location', {
            value: { assign: assignSpy },
        });
        renderLogin();
        fireEvent.input(screen.getByLabelText(/email address/i), {
            target: { value: 'user@example.com' },
        });
        fireEvent.input(screen.getByLabelText(/password/i), {
            target: { value: 'password' },
        });
        fireEvent.submit(screen.getByTestId('login-form'));
        await waitFor(() => expect(assignSpy).toHaveBeenCalledWith('/dashboard'));
    });

    it('submits remember value when checkbox is selected', async () => {
        submitMock.mockResolvedValue({ ok: false });
        renderLogin();
        fireEvent.click(screen.getByLabelText(/remember me/i));
        fireEvent.submit(screen.getByTestId('login-form'));
        await waitFor(() =>
            expect(submitMock).toHaveBeenCalledWith(
                expect.objectContaining({ remember: 'on' }),
            ),
        );
    });

    it('shows error message on invalid credentials', async () => {
        submitMock.mockResolvedValue({
            ok: false,
            errors: { email: 'Invalid credentials' },
        });
        renderLogin();
        fireEvent.submit(screen.getByTestId('login-form'));
        expect(await screen.findByText('Invalid credentials')).toBeInTheDocument();
    });
});
