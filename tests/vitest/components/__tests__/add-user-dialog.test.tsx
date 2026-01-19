import { router } from '@inertiajs/react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AddUserDialog } from '@/components/add-user-dialog';

// Mock Inertia router
vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
    },
}));

// Mock sonner toast
vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
}));

describe('AddUserDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('trigger button', () => {
        it('renders the add user button', () => {
            render(<AddUserDialog />);

            expect(screen.getByRole('button', { name: /add user/i })).toBeInTheDocument();
        });

        it('button is enabled by default', () => {
            render(<AddUserDialog />);

            expect(screen.getByRole('button', { name: /add user/i })).not.toBeDisabled();
        });

        it('button is disabled when disabled prop is true', () => {
            render(<AddUserDialog disabled />);

            expect(screen.getByRole('button', { name: /add user/i })).toBeDisabled();
        });
    });

    describe('dialog opening', () => {
        it('opens dialog when trigger button is clicked', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));

            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        it('displays dialog title', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));

            expect(screen.getByText('Add New User')).toBeInTheDocument();
        });

        it('displays dialog description', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));

            expect(screen.getByText(/create a new user account/i)).toBeInTheDocument();
            expect(screen.getByText(/beginner.*role/i)).toBeInTheDocument();
        });
    });

    describe('form fields', () => {
        it('renders name input field', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));

            expect(screen.getByLabelText(/name/i)).toBeInTheDocument();
            expect(screen.getByPlaceholderText('John Doe')).toBeInTheDocument();
        });

        it('renders email input field', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));

            expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
            expect(screen.getByPlaceholderText('john.doe@example.com')).toBeInTheDocument();
        });

        it('renders create user submit button', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));

            expect(screen.getByRole('button', { name: /create user/i })).toBeInTheDocument();
        });
    });

    describe('form input', () => {
        it('allows typing in name field', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/name/i), 'Test User');

            expect(screen.getByLabelText(/name/i)).toHaveValue('Test User');
        });

        it('allows typing in email field', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/email/i), 'test@example.com');

            expect(screen.getByLabelText(/email/i)).toHaveValue('test@example.com');
        });
    });

    describe('form submission', () => {
        it('calls router.post with correct data on submit', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/name/i), 'Test User');
            await user.type(screen.getByLabelText(/email/i), 'test@example.com');
            await user.click(screen.getByRole('button', { name: /create user/i }));

            expect(router.post).toHaveBeenCalledWith(
                '/users',
                { name: 'Test User', email: 'test@example.com' },
                expect.objectContaining({
                    preserveScroll: true,
                }),
            );
        });

        it('shows creating state while submitting', async () => {
            const user = userEvent.setup();
            vi.mocked(router.post).mockImplementation(() => {
                // Don't call onFinish, keeping isSubmitting true
            });

            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/name/i), 'Test User');
            await user.type(screen.getByLabelText(/email/i), 'test@example.com');
            await user.click(screen.getByRole('button', { name: /create user/i }));

            expect(screen.getByRole('button', { name: /creating/i })).toBeInTheDocument();
        });

        it('disables form fields while submitting', async () => {
            const user = userEvent.setup();
            vi.mocked(router.post).mockImplementation(() => {
                // Don't call onFinish
            });

            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/name/i), 'Test User');
            await user.type(screen.getByLabelText(/email/i), 'test@example.com');
            await user.click(screen.getByRole('button', { name: /create user/i }));

            expect(screen.getByLabelText(/name/i)).toBeDisabled();
            expect(screen.getByLabelText(/email/i)).toBeDisabled();
        });
    });

    describe('submission callbacks', () => {
        it('closes dialog and resets form on success', async () => {
            const user = userEvent.setup();
            vi.mocked(router.post).mockImplementation((_url, _data, options) => {
                const opts = options as Record<string, unknown> | undefined;
                const onSuccess = opts?.onSuccess as ((page: { props: { flash?: Record<string, string> } }) => void) | undefined;
                const onFinish = opts?.onFinish as (() => void) | undefined;
                onSuccess?.({ props: { flash: { success: 'User created' } } });
                onFinish?.();
            });

            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/name/i), 'Test User');
            await user.type(screen.getByLabelText(/email/i), 'test@example.com');
            await user.click(screen.getByRole('button', { name: /create user/i }));

            await waitFor(() => {
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            });
        });

        it('displays validation errors on error', async () => {
            const user = userEvent.setup();
            vi.mocked(router.post).mockImplementation((_url, _data, options) => {
                const opts = options as Record<string, unknown> | undefined;
                const onError = opts?.onError as ((errors: Record<string, string>) => void) | undefined;
                const onFinish = opts?.onFinish as (() => void) | undefined;
                onError?.({ email: 'The email has already been taken.' });
                onFinish?.();
            });

            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/name/i), 'Test User');
            await user.type(screen.getByLabelText(/email/i), 'existing@example.com');
            await user.click(screen.getByRole('button', { name: /create user/i }));

            await waitFor(() => {
                expect(screen.getByText('The email has already been taken.')).toBeInTheDocument();
            });
        });

        it('displays name validation error', async () => {
            const user = userEvent.setup();
            vi.mocked(router.post).mockImplementation((_url, _data, options) => {
                const opts = options as Record<string, unknown> | undefined;
                const onError = opts?.onError as ((errors: Record<string, string>) => void) | undefined;
                const onFinish = opts?.onFinish as (() => void) | undefined;
                onError?.({ name: 'The name is too short.' });
                onFinish?.();
            });

            render(<AddUserDialog />);

            await user.click(screen.getByRole('button', { name: /add user/i }));
            // Fill both fields since they have required attribute
            await user.type(screen.getByLabelText(/name/i), 'A');
            await user.type(screen.getByLabelText(/email/i), 'test@example.com');
            await user.click(screen.getByRole('button', { name: /create user/i }));

            await waitFor(() => {
                expect(screen.getByText('The name is too short.')).toBeInTheDocument();
            });
        });
    });

    describe('dialog closing', () => {
        it('resets form when dialog is closed', async () => {
            const user = userEvent.setup();
            render(<AddUserDialog />);

            // Open and fill form
            await user.click(screen.getByRole('button', { name: /add user/i }));
            await user.type(screen.getByLabelText(/name/i), 'Test User');
            await user.type(screen.getByLabelText(/email/i), 'test@example.com');

            // Close dialog with Escape
            await user.keyboard('{Escape}');

            // Reopen and check fields are empty
            await user.click(screen.getByRole('button', { name: /add user/i }));

            expect(screen.getByLabelText(/name/i)).toHaveValue('');
            expect(screen.getByLabelText(/email/i)).toHaveValue('');
        });
    });
});
