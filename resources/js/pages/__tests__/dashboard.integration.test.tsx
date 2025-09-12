import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import Dashboard from '../dashboard';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const usePageMock = vi.fn();
const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
    usePage: () => usePageMock(),
    router: routerMock,
    Link: ({ href, children, ...props }: { href: string; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
}));

describe('Dashboard integration', () => {
    beforeEach(() => {
        document.title = '';
        usePageMock.mockReturnValue({ props: { auth: { user: { name: 'Jane' } } } });
    });

    it('sets the document title', () => {
        render(<Dashboard />);
        expect(document.title).toBe('Dashboard');
    });
});

