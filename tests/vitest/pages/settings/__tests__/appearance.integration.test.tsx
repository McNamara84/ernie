import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Appearance from '@/pages/settings/appearance';

const usePageMock = vi.fn();

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
    usePage: () => usePageMock(),
    router: {
        put: vi.fn(),
    },
}));

vi.mock('@/components/appearance-tabs', () => ({
    default: () => <div />,
}));

vi.mock('@/components/font-size-toggle', () => ({
    default: () => <div />,
}));

vi.mock('@/components/heading-small', () => ({
    default: () => <div />,
}));

vi.mock('@/routes', () => ({
    appearance: () => ({ url: '/settings/appearance' }),
}));

describe('Appearance settings integration', () => {
    beforeEach(() => {
        document.title = '';
        usePageMock.mockReturnValue({ 
            props: { 
                fontSizePreference: 'regular',
                auth: { user: { id: 1 } } 
            } 
        });
    });

    it('sets the document title', () => {
        render(<Appearance />);
        expect(document.title).toBe('Appearance settings');
    });
});

