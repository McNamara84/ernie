import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import AppLogo from '@/components/app-logo';
import { afterEach, describe, expect, it, vi } from 'vitest';

afterEach(() => {
    vi.unstubAllEnvs();
});

describe('AppLogo', () => {
    it('falls back to Laravel when VITE_APP_NAME is undefined', () => {
        vi.stubEnv('VITE_APP_NAME', '');
        render(<AppLogo />);
        expect(screen.getByText('Laravel')).toBeInTheDocument();
    });

    it('renders custom app name from env', () => {
        vi.stubEnv('VITE_APP_NAME', 'Ernie');
        render(<AppLogo />);
        expect(screen.getByText('Ernie')).toBeInTheDocument();
    });
});

