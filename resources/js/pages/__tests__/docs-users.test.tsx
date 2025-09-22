import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { __testing as basePathTesting } from '@/lib/base-path';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

const AppLayoutMock = vi.fn(
    ({ breadcrumbs, children }: { breadcrumbs: unknown; children?: React.ReactNode }) => (
        <div data-breadcrumbs={JSON.stringify(breadcrumbs)}>{children}</div>
    ),
);

vi.mock('@/layouts/app-layout', () => ({
    default: AppLayoutMock,
}));

describe('User docs page', () => {
    afterEach(() => {
        basePathTesting.setMetaBasePath('');
        basePathTesting.resetBasePathCache();
        AppLayoutMock.mockClear();
        vi.resetModules();
    });

    it('renders curator instructions', async () => {
        const DocsUsers = (await import('../docs-users')).default;

        render(<DocsUsers />);
        expect(screen.getByText('Add new curators')).toBeInTheDocument();
        expect(
            screen.getByText(/ehrmann@gfz.de/)
        ).toBeInTheDocument();
        const container = screen.getByText('Add new curators').closest('[data-breadcrumbs]');
        expect(container).toHaveAttribute(
            'data-breadcrumbs',
            JSON.stringify([
                { title: 'Documentation', href: '/docs' },
                { title: 'User guide', href: '/docs/users' },
            ]),
        );
    });

    it('includes the base path in breadcrumb links when configured', async () => {
        basePathTesting.setMetaBasePath('/ernie');
        basePathTesting.resetBasePathCache();

        vi.resetModules();
        const DocsUsers = (await import('../docs-users')).default;

        render(<DocsUsers />);

        const container = screen.getByText('Add new curators').closest('[data-breadcrumbs]');
        expect(container).toHaveAttribute(
            'data-breadcrumbs',
            JSON.stringify([
                { title: 'Documentation', href: '/ernie/docs' },
                { title: 'User guide', href: '/ernie/docs/users' },
            ]),
        );
    });
});
