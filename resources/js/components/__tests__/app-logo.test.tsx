import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import AppLogo from '../app-logo';
import { afterEach, describe, expect, it } from 'vitest';

// Preserve original environment variable
const originalAppName = import.meta.env.VITE_APP_NAME;

afterEach(() => {
    if (originalAppName === undefined) {
        delete (import.meta.env as any).VITE_APP_NAME;
    } else {
        (import.meta.env as any).VITE_APP_NAME = originalAppName;
    }
});

describe('AppLogo', () => {
    it('falls back to Laravel when VITE_APP_NAME is undefined', () => {
        delete (import.meta.env as any).VITE_APP_NAME;
        render(<AppLogo />);
        expect(screen.getByText('Laravel')).toBeInTheDocument();
    });

    it('renders custom app name from env', () => {
        (import.meta.env as any).VITE_APP_NAME = 'Ernie';
        render(<AppLogo />);
        expect(screen.getByText('Ernie')).toBeInTheDocument();
    });
});

