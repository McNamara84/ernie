import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ComponentProps, type ReactNode,useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import VerifyEmail from '@/pages/auth/verify-email';

vi.mock('@/layouts/auth-layout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/EmailVerificationNotificationController', () => ({
  default: { store: { form: () => ({}) } },
}));

vi.mock('@/components/text-link', () => ({
  default: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/ui/button', () => ({
  Button: ({ children, ...props }: ComponentProps<'button'>) => <button {...props}>{children}</button>,
}));

vi.mock('@/routes', () => ({
  logout: () => '/logout',
}));

vi.mock('@inertiajs/react', () => {
  return {
    Form: ({ children }: { children?: ReactNode | ((args: { processing: boolean }) => ReactNode) }) => {
      const [processing, setProcessing] = useState(false);
      const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        setProcessing(true);
        await fetch('/email/verification-notification', { method: 'POST' });
        setProcessing(false);
      };
      return (
        <form onSubmit={handleSubmit}>
          {typeof children === 'function' ? children({ processing }) : children}
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

describe('VerifyEmail integration', () => {
  it('submits resend verification request', async () => {
    const fetchSpy = vi.fn(async () => ({ ok: true }));
    vi.stubGlobal('fetch', fetchSpy);
    render(<VerifyEmail />);
    const button = screen.getByRole('button', { name: /resend verification email/i });
    const form = button.closest('form');
    if (!form) throw new Error('Form not found');
    fireEvent.submit(form);
    await waitFor(() => expect(fetchSpy).toHaveBeenCalled());
    expect(fetchSpy).toHaveBeenCalledWith('/email/verification-notification', expect.objectContaining({ method: 'POST' }));
  });
});

