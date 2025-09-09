import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState, type ComponentProps, type ReactNode } from 'react';
import ForgotPassword from '../forgot-password';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/auth-layout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/PasswordResetLinkController', () => ({
  default: { store: { form: () => ({}) } },
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

vi.mock('@/components/ui/input', () => ({
  Input: (props: ComponentProps<'input'>) => <input {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
  Label: ({ children, ...props }: ComponentProps<'label'>) => <label {...props}>{children}</label>,
}));

vi.mock('@inertiajs/react', () => {
  return {
    Form: ({ children }: { children?: ReactNode | ((args: { processing: boolean; errors: Record<string, string> }) => ReactNode) }) => {
      const [processing, setProcessing] = useState(false);
      const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        setProcessing(true);
        const data = new FormData(e.currentTarget);
        await fetch('/forgot-password', { method: 'POST', body: data });
        setProcessing(false);
      };
      return (
        <form onSubmit={handleSubmit}>
          {typeof children === 'function' ? children({ processing, errors: {} }) : children}
        </form>
      );
    },
    Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
    Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
  };
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('ForgotPassword integration', () => {
  it('submits email to server', async () => {
    const fetchSpy = vi.fn(async () => ({ ok: true }));
    vi.stubGlobal('fetch', fetchSpy);
    render(<ForgotPassword />);
    fireEvent.input(screen.getByLabelText(/email address/i), { target: { value: 'user@example.com' } });
    fireEvent.submit(screen.getByRole('button', { name: /email password reset link/i }).closest('form')!);
    await waitFor(() => expect(fetchSpy).toHaveBeenCalled());
    expect(fetchSpy).toHaveBeenCalledWith('/forgot-password', expect.objectContaining({ method: 'POST' }));
  });
});

