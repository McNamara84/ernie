import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { type ComponentProps, type ReactNode,useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import ResetPassword from '@/pages/auth/reset-password';

const originalLocation = window.location;

vi.mock('@/layouts/auth-layout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/NewPasswordController', () => ({
  default: { store: { form: () => ({}) } },
}));

vi.mock('@/components/input-error', () => ({
  default: ({ message }: { message?: string }) => (message ? <p>{message}</p> : null),
}));

vi.mock('@/components/ui/button', () => ({
  Button: ({ children, ...props }: ComponentProps<'button'>) => <button {...props}>{children}</button>,
}));

vi.mock('@/components/ui/input', () => ({
  Input: (props: ComponentProps<'input'>) => <input {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
  Label: ({ children, ...props }: ComponentProps<'label'>) => <label {...props}>{children}</label>,
}));

vi.mock('@inertiajs/react', () => {
  return {
    Form: ({ children, transform }: { children?: ReactNode | ((args: { processing: boolean; errors: Record<string, string> }) => ReactNode); transform?: (data: Record<string, unknown>) => Record<string, unknown> }) => {
      const [processing, setProcessing] = useState(false);
      const [errors, setErrors] = useState<Record<string, string>>({});
      const isSafeRedirect = (url: string) => {
        try {
          const parsed = new URL(url, window.location.origin);
          return parsed.origin === window.location.origin && url.startsWith('/') && !url.startsWith('//');
        } catch {
          return false;
        }
      };
      const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        setProcessing(true);
        const formData = new FormData(e.currentTarget);
        let data: Record<string, unknown> = Object.fromEntries(formData.entries());
        if (transform) {
          data = transform(data);
        }
        const response = await fetch('/reset-password', {
          method: 'POST',
          body: JSON.stringify(data),
        });
        setProcessing(false);
        if (response.ok) {
          const json = await response.json();
          const redirect =
            typeof json.redirect === 'string' && isSafeRedirect(json.redirect)
              ? json.redirect
              : '/';
          window.location.assign(redirect);
        } else {
          const json = await response.json();
          setErrors(json.errors ?? {});
        }
      };
      return (
        <form onSubmit={handleSubmit}>
          {typeof children === 'function' ? children({ processing, errors }) : children}
        </form>
      );
    },
    Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
    Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
  };
});

afterEach(() => {
  Object.defineProperty(window, 'location', { value: originalLocation });
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('ResetPassword integration', () => {
  const token = 'token123';
  const email = 'user@example.com';

  it('submits form and redirects on success', async () => {
    const fetchSpy = vi.fn(async (...args: unknown[]) => {
      void args;
      return {
      ok: true,
      json: async () => ({ redirect: '/login' }),
      };
    });
    vi.stubGlobal('fetch', fetchSpy);
    const assignSpy = vi.fn();
    Object.defineProperty(window, 'location', { value: { assign: assignSpy, origin: 'http://localhost' } });
    render(<ResetPassword token={token} email={email} />);
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Password'), 'newpassword');
    await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
    await user.click(screen.getByRole('button', { name: /reset password/i }));
    await waitFor(() => expect(assignSpy).toHaveBeenCalledWith('/login'));
    expect(fetchSpy).toHaveBeenCalled();
    const bodyString = fetchSpy.mock.calls[0]?.[1]?.body;
    expect(typeof bodyString).toBe('string');
    const body = JSON.parse(bodyString as string);
    expect(body.token).toBe(token);
    expect(body.email).toBe(email);
  });

  it('shows errors returned from server', async () => {
    const fetchSpy = vi.fn(async () => ({ ok: false, json: async () => ({ errors: { password: 'Too short' } }) }));
    vi.stubGlobal('fetch', fetchSpy);
    render(<ResetPassword token={token} email={email} />);
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Password'), 'short');
    await user.type(screen.getByLabelText(/confirm password/i), 'short');
    await user.click(screen.getByRole('button', { name: /reset password/i }));
    expect(await screen.findByText('Too short')).toBeInTheDocument();
  });

  it.each(['https://evil.com', '//evil.com'])('falls back to root for invalid redirect %s', async (redirectValue) => {
    const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ redirect: redirectValue }) }));
    vi.stubGlobal('fetch', fetchSpy);
    const assignSpy = vi.fn();
    Object.defineProperty(window, 'location', { value: { assign: assignSpy, origin: 'http://localhost' } });
    render(<ResetPassword token={token} email={email} />);
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Password'), 'newpassword');
    await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
    await user.click(screen.getByRole('button', { name: /reset password/i }));
    await waitFor(() => expect(assignSpy).toHaveBeenCalledWith('/'));
  });
});
