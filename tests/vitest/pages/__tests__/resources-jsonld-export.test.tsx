import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }));
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const editorRouteMock = vi.hoisted(
    () =>
        vi.fn(
            ({ query }: { query?: Record<string, string> } = {}) => ({
                url: query ? `/editor?${new URLSearchParams(query).toString()}` : '/editor',
                method: 'get',
            }),
        ),
);

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({
        props: {
            auth: {
                user: {
                    can_manage_landing_pages: true,
                },
            },
        },
    }),
}));

vi.mock('@/lib/curation-query', () => ({
    buildCurationQueryFromResource: buildCurationQueryFromResourceMock,
}));

vi.mock('@/routes', () => ({
    editor: editorRouteMock,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

const defaultProps = {
    resources: [
        {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            version: '2.0',
            created_at: '2024-04-01T09:00:00Z',
            updated_at: '2024-04-02T10:00:00Z',
            resourcetypegeneral: 'Dataset',
            title: 'Primary title',
            first_author: { givenName: 'John', familyName: 'Doe' },
            curator: 'Test Curator',
            publicstatus: 'published',
        },
    ],
    pagination: {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 1,
        from: 1,
        to: 1,
        has_more: false,
    },
    sort: {
        key: 'id' as const,
        direction: 'asc' as const,
    },
};

describe('Resources JSON-LD Export Button', () => {
    it('renders a JSON-LD export button for each resource', () => {
        buildCurationQueryFromResourceMock.mockResolvedValue({});

        render(<ResourcesPage {...defaultProps} />);

        const jsonLdButton = screen.getByRole('button', {
            name: /export resource.*10\.9999\/example.*as json-ld/i,
        });
        expect(jsonLdButton).toBeInTheDocument();
        expect(jsonLdButton).not.toBeDisabled();
    });

    it('renders JSON-LD button with correct title attribute', () => {
        buildCurationQueryFromResourceMock.mockResolvedValue({});

        render(<ResourcesPage {...defaultProps} />);

        const jsonLdButton = screen.getByRole('button', {
            name: /export resource.*as json-ld/i,
        });
        expect(jsonLdButton).toHaveAttribute('title', 'Export as JSON-LD (Linked Data)');
    });

    it('renders JSON-LD button alongside JSON and XML export buttons', () => {
        buildCurationQueryFromResourceMock.mockResolvedValue({});

        render(<ResourcesPage {...defaultProps} />);

        const table = screen.getByRole('table');
        const dataRows = within(table).getAllByRole('row').slice(1);
        const actionCell = dataRows[0];

        // All three export buttons should be present
        expect(within(actionCell).getByRole('button', { name: /as datacite json/i })).toBeInTheDocument();
        expect(within(actionCell).getByRole('button', { name: /as datacite xml/i })).toBeInTheDocument();
        expect(within(actionCell).getByRole('button', { name: /as json-ld/i })).toBeInTheDocument();
    });
});
