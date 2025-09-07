import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import DeleteUser from '../delete-user';

const onErrorMock = vi.fn();

vi.mock('@/actions/App/Http/Controllers/Settings/ProfileController', () => ({
    default: {
        destroy: { form: () => ({ action: '/delete', method: 'post' }) },
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
    Button: ({ children, asChild, ...props }: any) =>
        asChild && React.isValidElement(children)
            ? React.cloneElement(children, props)
            : <button {...props}>{children}</button>,
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DialogTrigger: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DialogContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
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
    Label: ({ children, ...props }: any) => <label {...props}>{children}</label>,
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({ children, onError }: any) => {
        onErrorMock.mockImplementation(onError);
        return <div>{children({ resetAndClearErrors: vi.fn(), processing: false, errors: {} })}</div>;
    },
}));

vi.mock('@/components/input-error', () => ({
    default: ({ message }: { message?: string }) => (message ? <div>{message}</div> : null),
}));

describe('DeleteUser', () => {
    it('renders warning and delete button', () => {
        render(<DeleteUser />);
        const buttons = screen.getAllByRole('button', { name: 'Delete account' });
        expect(buttons.length).toBeGreaterThan(0);
        expect(
            screen.getByText('Please proceed with caution, this cannot be undone.'),
        ).toBeInTheDocument();
    });

    it('focuses password input when onError is called', () => {
        render(<DeleteUser />);
        const input = screen.getByPlaceholderText('Password') as HTMLInputElement;
        expect(document.activeElement).not.toBe(input);
        onErrorMock({});
        expect(document.activeElement).toBe(input);
    });
});

