import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({ children, onError }: { children: (args: { errors: Record<string, string>; processing: boolean; recentlySuccessful: boolean }) => React.ReactNode; onError?: (e: Record<string, string>) => void; }) => {
        const [processing, setProcessing] = React.useState(false);
        const [errors, setErrors] = React.useState<Record<string, string>>({});
        const [recentlySuccessful, setRecentlySuccessful] = React.useState(false);
        const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
            e.preventDefault();
            setProcessing(true);
            const data = new FormData(e.currentTarget);
            const response = await fetch('/settings/password', { method: 'POST', body: data });
            setProcessing(false);
            if (response.ok) {
                setErrors({});
                setRecentlySuccessful(true);
            } else {
                const json = await response.json();
                setErrors(json.errors ?? {});
                onError?.(json.errors ?? {});
            }
        };
        return <form onSubmit={handleSubmit}>{children({ errors, processing, recentlySuccessful })}</form>;
    },
}));

vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/layouts/settings/layout', () => ({ default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/components/heading-small', () => ({ default: ({ title, description }: { title: string; description: string }) => (<div><h2>{title}</h2><p>{description}</p></div>) }));
vi.mock('@/components/input-error', () => ({ default: ({ message }: { message?: string }) => (message ? <p>{message}</p> : null) }));
vi.mock('@/components/ui/button', () => ({ Button: ({ children, ...props }: React.ComponentProps<'button'>) => <button {...props}>{children}</button> }));
vi.mock('@/components/ui/input', () => ({ Input: (props: React.ComponentProps<'input'>) => <input {...props} /> }));
vi.mock('@/components/ui/label', () => ({ Label: ({ children, ...props }: React.ComponentProps<'label'>) => <label {...props}>{children}</label> }));
vi.mock('@/routes/password', () => ({ edit: () => ({ url: '/settings/password' }) }));
vi.mock('@/actions/App/Http/Controllers/Settings/PasswordController', () => ({ default: { update: { form: () => ({}) } } }));

import Password from '@/pages/settings/password';

afterEach(() => {
    vi.resetAllMocks();
    vi.unstubAllGlobals();
});

describe('Password settings integration', () => {
    it('updates password successfully', async () => {
        const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({}) }));
        vi.stubGlobal('fetch', fetchMock);
        render(<Password />);
        fireEvent.input(screen.getByLabelText(/current password/i), { target: { value: 'oldpass' } });
        fireEvent.input(screen.getByLabelText(/^new password$/i), { target: { value: 'newpass' } });
        fireEvent.input(screen.getByLabelText(/confirm password/i), { target: { value: 'newpass' } });
        const button = screen.getByRole('button', { name: /save password/i });
        const form = button.closest('form');
        if (!form) {
            throw new Error('Save password button is not inside a form');
        }
        fireEvent.submit(form);
        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(await screen.findByText('Saved')).toBeInTheDocument();
    });

    it('focuses current password on error', async () => {
        const fetchMock = vi.fn(async () => ({ ok: false, json: async () => ({ errors: { current_password: 'Incorrect' } }) }));
        vi.stubGlobal('fetch', fetchMock);
        render(<Password />);
        const current = screen.getByLabelText(/current password/i) as HTMLInputElement;
        const button = screen.getByRole('button', { name: /save password/i });
        const form = button.closest('form');
        if (!form) {
            throw new Error('Save password button is not inside a form');
        }
        fireEvent.submit(form);
        expect(await screen.findByText('Incorrect')).toBeInTheDocument();
        expect(document.activeElement).toBe(current);
    });
});
