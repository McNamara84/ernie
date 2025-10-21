import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Appearance from '@/pages/settings/appearance';

const updateAppearance = vi.fn();
const updateFontSize = vi.fn();
const usePageMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    usePage: () => usePageMock(),
    router: {
        put: vi.fn(),
    },
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

vi.mock('@/hooks/use-font-size', () => ({
    useFontSize: () => ({ fontSize: 'regular', updateFontSize }),
}));

vi.mock('@/routes', () => ({
    appearance: () => ({ url: '/settings/appearance' }),
    about: () => '/about',
    legalNotice: () => '/legal-notice',
}));

describe('Appearance settings page', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({ 
            props: { 
                fontSizePreference: 'regular',
                auth: { user: { id: 1 } } 
            } 
        });
    });

    it('renders heading and updates appearance', () => {
        render(<Appearance />);
        expect(screen.getByRole('heading', { name: /appearance settings/i })).toBeInTheDocument();
        const darkButton = screen.getByRole('button', { name: /dark/i });
        fireEvent.click(darkButton);
        expect(updateAppearance).toHaveBeenCalledWith('dark');
    });
});
