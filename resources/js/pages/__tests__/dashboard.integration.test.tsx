import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import Dashboard from '../dashboard';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const usePageMock = vi.fn();
const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

function resolveHref(href: unknown): string {
    if (typeof href === 'string') {
        return href;
    }

    if (href && typeof href === 'object' && 'url' in href && typeof (href as { url?: unknown }).url === 'string') {
        return (href as { url: string }).url;
    }

    return '';
}

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
    usePage: () => usePageMock(),
    router: routerMock,
    Link: ({ href, children, ...props }: { href: unknown; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={resolveHref(href)} {...props}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

function createRoute(path: string) {
    const routeFn = () => ({ url: path });
    routeFn.url = () => path;
    return routeFn;
}

vi.mock('@/routes', () => ({
    dashboard: createRoute('/dashboard'),
    changelog: createRoute('/changelog'),
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

