import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const authUser = { name: 'John Doe', email: 'john@example.com', email_verified_at: null };

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { auth: { user: authUser } } }),
    Form: ({ children }: { children: (args: { processing: boolean; recentlySuccessful: boolean; errors: Record<string, string> }) => React.ReactNode }) => {
        const [processing, setProcessing] = React.useState(false);
        const [errors, setErrors] = React.useState<Record<string, string>>({});
        const [recentlySuccessful, setRecentlySuccessful] = React.useState(false);
        const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
            e.preventDefault();
            setProcessing(true);
            const data = new FormData(e.currentTarget);
            const response = await fetch('/settings/profile', { method: 'POST', body: data });
            setProcessing(false);
            if (response.ok) {
                setErrors({});
                setRecentlySuccessful(true);
            } else {
                const json = await response.json();
                setErrors(json.errors ?? {});
            }
        };
        return <form onSubmit={handleSubmit}>{children({ processing, recentlySuccessful, errors })}</form>;
    },
}));

vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/layouts/settings/layout', () => ({ default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/components/delete-user', () => ({ default: () => <div /> }));
vi.mock('@/components/heading-small', () => ({ default: ({ title, description }: { title: string; description: string }) => (<div><h2>{title}</h2><p>{description}</p></div>) }));
vi.mock('@/components/input-error', () => ({ default: ({ message }: { message?: string }) => (message ? <p>{message}</p> : null) }));
vi.mock('@/components/ui/button', () => ({ Button: ({ children, ...props }: React.ComponentProps<'button'>) => <button {...props}>{children}</button> }));
vi.mock('@/components/ui/input', () => ({ Input: (props: React.ComponentProps<'input'>) => <input {...props} /> }));
vi.mock('@/components/ui/label', () => ({ Label: ({ children, ...props }: React.ComponentProps<'label'>) => <label {...props}>{children}</label> }));
vi.mock('@/routes/profile', () => ({ edit: () => ({ url: '/settings/profile' }) }));
vi.mock('@/routes/verification', () => ({ send: () => '/email/verification-notification' }));
vi.mock('@/actions/App/Http/Controllers/Settings/ProfileController', () => ({ default: { update: { form: () => ({}) } } }));

import Profile from '@/pages/settings/profile';

afterEach(() => {
    vi.resetAllMocks();
    vi.unstubAllGlobals();
});

describe('Profile settings integration', () => {
    it('submits profile successfully', async () => {
        const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({}) }));
        vi.stubGlobal('fetch', fetchMock);
        render(<Profile mustVerifyEmail={false} />);
        fireEvent.input(screen.getByLabelText(/name/i), { target: { value: 'Jane Doe' } });
        fireEvent.input(screen.getByLabelText(/email address/i), { target: { value: 'jane@example.com' } });
        const button = screen.getByRole('button', { name: /^save$/i });
        const form = button.closest('form');
        if (!form) {
            throw new Error('Save button is not inside a form');
        }
        fireEvent.submit(form);
        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(await screen.findByText('Saved')).toBeInTheDocument();
    });

    it('shows validation errors from server', async () => {
        const fetchMock = vi.fn(async () => ({ ok: false, json: async () => ({ errors: { name: 'Required' } }) }));
        vi.stubGlobal('fetch', fetchMock);
        render(<Profile mustVerifyEmail={false} />);
        const button = screen.getByRole('button', { name: /^save$/i });
        const form = button.closest('form');
        if (!form) {
            throw new Error('Save button is not inside a form');
        }
        fireEvent.submit(form);
        expect(await screen.findByText('Required')).toBeInTheDocument();
    });
});
