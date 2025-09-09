import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState, type ComponentProps, type ReactNode } from 'react';
import ConfirmPassword from '../confirm-password';
import { afterEach, describe, expect, it, vi } from 'vitest';

const originalLocation = window.location;

vi.mock('@/layouts/auth-layout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/ConfirmablePasswordController', () => ({
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
    Form: ({ children }: { children?: ReactNode | ((args: { processing: boolean; errors: Record<string, string> }) => ReactNode) }) => {
      const [errors, setErrors] = useState<Record<string, string>>({});
      const [processing, setProcessing] = useState(false);
      const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        setProcessing(true);
        const data = new FormData(e.currentTarget);
        const response = await fetch('/confirm-password', { method: 'POST', body: data });
        setProcessing(false);
        if (response.ok) {
          const json = await response.json();
          const redirect = typeof json.redirect === 'string' && json.redirect.startsWith('/') ? json.redirect : '/';
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

describe('ConfirmPassword integration', () => {
  it('redirects after successful confirmation', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: true,
      json: async () => ({ redirect: '/dashboard' }),
    })));
    const assignSpy = vi.fn();
    Object.defineProperty(window, 'location', { value: { assign: assignSpy } });
    render(<ConfirmPassword />);
    fireEvent.input(screen.getByLabelText(/password/i), { target: { value: 'password' } });
    fireEvent.submit(screen.getByRole('button', { name: /confirm password/i }).closest('form')!);
    await waitFor(() => expect(assignSpy).toHaveBeenCalledWith('/dashboard'));
  });

  it('shows error message on failure', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: false,
      json: async () => ({ errors: { password: 'Invalid password' } }),
    })));
    render(<ConfirmPassword />);
    fireEvent.input(screen.getByLabelText(/password/i), { target: { value: 'wrong' } });
    fireEvent.submit(screen.getByRole('button', { name: /confirm password/i }).closest('form')!);
    expect(await screen.findByText('Invalid password')).toBeInTheDocument();
  });
});
