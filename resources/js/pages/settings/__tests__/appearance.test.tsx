import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Appearance from '../appearance';

const updateAppearance = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'system', updateAppearance }),
}));

vi.mock('@/routes', () => ({
    appearance: () => ({ url: '/settings/appearance' }),
}));

describe('Appearance settings page', () => {
    it('renders heading and updates appearance', () => {
        render(<Appearance />);
        expect(screen.getByRole('heading', { name: /appearance settings/i })).toBeInTheDocument();
        const darkButton = screen.getByRole('button', { name: /dark/i });
        fireEvent.click(darkButton);
        expect(updateAppearance).toHaveBeenCalledWith('dark');
    });
});
