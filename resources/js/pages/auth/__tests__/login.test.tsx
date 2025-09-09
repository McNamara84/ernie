import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState, type ComponentProps, type ReactNode } from 'react';
import Login from '../login';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

interface MockFormState {
    errors: Record<string, string>;
    processing: boolean;
}

const mockFormState: MockFormState = { errors: {}, processing: false };
const setMockFormState = (state: Partial<MockFormState>) => {
    Object.assign(mockFormState, state);
};
const resetMockFormState = () => {
    mockFormState.errors = {};
    mockFormState.processing = false;
};
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
            const [errors, setErrors] = useState(mockFormState.errors);
            const [processing, setProcessing] = useState(mockFormState.processing);
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
                <form onSubmit={handleSubmit}>
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

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, ...props }: ComponentProps<'label'>) => <label {...props}>{children}</label>,
}));

beforeEach(() => {
    resetMockFormState();
    submitMock.mockReset();
});

afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation });
    vi.restoreAllMocks();
});

describe('Login page', () => {
    it('renders forgot password link when allowed', () => {
        render(<Login canResetPassword={true} />);
        const link = screen.getByRole('link', { name: /forgot password/i });
        expect(link).toHaveAttribute('href', '/forgot-password');
    });

    it('displays status message when provided', () => {
        render(<Login canResetPassword={false} status="Password reset" />);
        expect(screen.getByText('Password reset')).toBeInTheDocument();
    });

    it('renders validation errors', () => {
        setMockFormState({ errors: { email: 'Invalid email', password: 'Required' } });
        render(<Login canResetPassword={false} />);
        expect(screen.getByText('Invalid email')).toBeInTheDocument();
        expect(screen.getByText('Required')).toBeInTheDocument();
    });

    it('disables the submit button and shows a spinner while processing', () => {
        setMockFormState({ processing: true });
        render(<Login canResetPassword={false} />);
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
        render(<Login canResetPassword={false} />);
        fireEvent.input(screen.getByLabelText(/email address/i), {
            target: { value: 'user@example.com' },
        });
        fireEvent.input(screen.getByLabelText(/password/i), {
            target: { value: 'password' },
        });
        fireEvent.submit(screen.getByRole('button', { name: /log in/i }).closest('form')!);
        await waitFor(() => expect(assignSpy).toHaveBeenCalledWith('/dashboard'));
    });

    it('shows error message on invalid credentials', async () => {
        submitMock.mockResolvedValue({
            ok: false,
            errors: { email: 'Invalid credentials' },
        });
        render(<Login canResetPassword={false} />);
        fireEvent.submit(screen.getByRole('button', { name: /log in/i }).closest('form')!);
        expect(await screen.findByText('Invalid credentials')).toBeInTheDocument();
    });
});
