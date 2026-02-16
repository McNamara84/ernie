import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DeleteUser from '@/components/delete-user';

const { routerMock } = vi.hoisted(() => ({
    routerMock: {
        delete: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: routerMock,
}));

vi.mock('@/actions/App/Http/Controllers/Settings/ProfileController', () => ({
    default: {
        destroy: { url: () => '/profile' },
    },
}));

vi.mock('@/components/heading-small', () => ({
    default: ({ title, description }: { title: string; description: string }) => (
        <div>
            <h2>{title}</h2>
            <p>{description}</p>
        </div>
    ),
}));

vi.mock('@/components/ui/button', () => ({
    Button: (
        { children, asChild, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & {
            asChild?: boolean;
        },
    ) =>
        asChild && React.isValidElement(children)
            ? React.cloneElement(children, props)
            : <button {...props}>{children}</button>,
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DialogTrigger: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DialogContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DialogHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DialogTitle: ({ children }: { children: React.ReactNode }) => <h3>{children}</h3>,
    DialogDescription: ({ children }: { children: React.ReactNode }) => <p>{children}</p>,
    DialogFooter: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DialogClose: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/input', () => ({
    Input: React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>((props, ref) => (
        <input {...props} ref={ref} />
    )),
}));

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) => (
        <label {...props}>{children}</label>
    ),
}));

describe('DeleteUser', () => {
    beforeEach(() => {
        routerMock.delete.mockReset();
    });

    it('renders warning and delete button', () => {
        render(<DeleteUser />);
        const buttons = screen.getAllByRole('button', { name: 'Delete account' });
        expect(buttons.length).toBeGreaterThan(0);
        expect(
            screen.getByText('Please proceed with caution, this cannot be undone.'),
        ).toBeInTheDocument();
    });

    it('shows validation error when submitting without password', async () => {
        render(<DeleteUser />);
        const user = userEvent.setup();

        // Find the delete submit button inside the dialog (second one)
        const deleteButtons = screen.getAllByRole('button', { name: 'Delete account' });
        const submitButton = deleteButtons[deleteButtons.length - 1];
        await user.click(submitButton);

        await waitFor(() => {
            expect(screen.getByText(/password is required to confirm deletion/i)).toBeInTheDocument();
        });
    });

    it('calls router.delete when form is submitted with valid password', async () => {
        routerMock.delete.mockImplementation((_url: string, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<DeleteUser />);
        const user = userEvent.setup();

        await user.type(screen.getByPlaceholderText('Password'), 'mypassword');
        const deleteButtons = screen.getAllByRole('button', { name: 'Delete account' });
        const submitButton = deleteButtons[deleteButtons.length - 1];
        await user.click(submitButton);

        await waitFor(() => {
            expect(routerMock.delete).toHaveBeenCalledWith(
                '/profile',
                expect.objectContaining({
                    data: expect.objectContaining({ password: 'mypassword' }),
                }),
            );
        });
    });
});
