import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }));
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const editorRouteMock = vi.hoisted(() =>
    vi.fn(({ query }: { query?: Record<string, string> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(query).toString()}` : '/editor',
        method: 'get',
    })),
);

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({
        props: {
            auth: {
                user: {
                    can_manage_landing_pages: true,
                    role: 'group_leader',
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

describe('ResourcesPage', () => {
    beforeEach(() => {
        routerMock.get.mockClear();
        routerMock.delete.mockClear();
        buildCurationQueryFromResourceMock.mockReset();
        buildCurationQueryFromResourceMock.mockResolvedValue({});
        editorRouteMock.mockClear();
    });

    afterEach(() => {
        document.head.innerHTML = '';
    });

    it('renders a table with the streamlined dataset overview', () => {
        const props = {
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
                last_page: 3,
                per_page: 50,
                total: 60,
                from: 1,
                to: 50,
                has_more: true,
            },
            sort: {
                key: 'id' as const,
                direction: 'asc' as const,
            },
        };

        buildCurationQueryFromResourceMock.mockResolvedValue({
            resourceId: '1',
            doi: '10.9999/example',
        });

        render(<ResourcesPage {...props} />);

        expect(screen.getByTestId('app-layout')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1, name: /resources/i })).toBeInTheDocument();
        // New implementation doesn't show pagination summary text
        // expect(screen.getByText(/showing 1–50 of 60 resources/i)).toBeInTheDocument();

        const table = screen.getByRole('table');
        expect(table).toBeInTheDocument();
        expect(within(table).getByRole('group', { name: /sort options for id and resource type/i })).toBeInTheDocument();
        expect(within(table).getByRole('group', { name: /sort options for doi and title/i })).toBeInTheDocument();

        const dataRows = within(table).getAllByRole('row').slice(1);
        const cells = within(dataRows[0]).getAllByRole('cell');
        const idResourceTypeCell = cells[2];
        const doiTitleCell = cells[3];

        expect(Array.from(idResourceTypeCell.querySelectorAll('span')).map((span) => span.textContent)).toEqual(['#1', 'Dataset']);
        expect(Array.from(doiTitleCell.querySelectorAll('span')).map((span) => span.textContent)).toEqual(['10.9999/example', 'Primary title']);

        expect(screen.getByRole('columnheader', { name: /actions/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /open resource.*10\.9999\/example.*in.*editor/i })).toBeInTheDocument();
        const deleteButton = screen.getByRole('button', { name: /delete resource.*10\.9999\/example/i });
        expect(deleteButton).toBeDisabled();
        expect(deleteButton).toHaveAttribute('title', 'Only draft resources can be deleted');
    });

    it('shows a friendly empty state when there are no resources', () => {
        render(
            <ResourcesPage
                resources={[]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 0,
                    from: 0,
                    to: 0,
                    has_more: false,
                }}
                sort={{ key: 'id', direction: 'asc' }}
            />,
        );

        expect(screen.getByText(/no resources found/i)).toBeInTheDocument();
    });

    it('uses a friendly placeholder when a resource has no DOI', () => {
        const props = {
            resources: [
                {
                    id: 99,
                    doi: null,
                    year: 2023,
                    title: 'Placeholder title',
                    resourcetypegeneral: 'Dataset',
                    curator: undefined,
                    publicstatus: 'curation',
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
            sort: { key: 'id' as const, direction: 'asc' as const },
        };

        render(<ResourcesPage {...props} />);

        const dataRows = screen.getAllByRole('row').slice(1);
        expect(within(dataRows[0]).getByText('Not registered')).toBeInTheDocument();

        expect(screen.getByRole('button', { name: /delete resource.*placeholder title/i })).toBeDisabled();
    });

    it('opens the curation editor with prefilled metadata when the action is triggered', async () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'published',
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        const editButton = screen.getByRole('button', {
            name: /open resource.*10\.9999\/example.*in.*editor/i,
        });

        await act(async () => {
            fireEvent.click(editButton);
            await Promise.resolve();
        });

        // New implementation navigates directly with resourceId, not using buildCurationQueryFromResource
        expect(editorRouteMock).toHaveBeenCalledWith({
            query: { resourceId: resource.id },
        });
        const lastCall = routerMock.get.mock.calls.at(-1);
        expect(lastCall?.[0]).toBe('/editor?resourceId=1');
    });
});
