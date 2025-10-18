import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }));
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const editorRouteMock = vi.hoisted(
    () =>
        vi.fn(
            ({ query }: { query?: Record<string, string> } = {}) => ({
                url: query
                    ? `/editor?${new URLSearchParams(query).toString()}`
                    : '/editor',
                method: 'get',
            }),
        ),
);

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
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
        expect(within(table).getByText('Primary title')).toBeInTheDocument();
        expect(within(table).getByRole('columnheader', { name: /id.*doi/i })).toBeInTheDocument();
        
        const dataRows = within(table).getAllByRole('row').slice(1);
        expect(within(dataRows[0]).getByText('#1')).toBeInTheDocument();
        // DOI is now shown as text, not as a link
        expect(within(dataRows[0]).getByText('10.9999/example')).toBeInTheDocument();
        
        expect(screen.getByRole('columnheader', { name: /actions/i })).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /open resource.*10\.9999\/example.*in.*editor/i }),
        ).toBeInTheDocument();
        // Delete functionality is not yet implemented (disabled button)
        const deleteButton = screen.getByRole('button', { name: /delete resource.*10\.9999\/example.*not yet implemented/i });
        expect(deleteButton).toBeDisabled();
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
                    has_more: false 
                }}
                sort={{ key: 'id', direction: 'asc' }}
            />,
        );

        expect(screen.getByText(/no resources found/i)).toBeInTheDocument();
    });

    // Pagination was replaced with infinite scrolling in the new implementation
    it.skip('navigates between pages using inertia router', () => {
        const props = {
            resources: [
                {
                    id: 1,
                    doi: null,
                    year: 2024,
                    title: 'Test Resource',
                    resourcetypegeneral: 'Dataset',
                    curator: 'Test Curator',
                    publicstatus: 'curation',
                },
            ],
            pagination: {
                current_page: 2,
                last_page: 4,
                per_page: 50,
                total: 80,
                from: 51,
                to: 100,
                has_more: true,
            },
            sort: { key: 'id' as const, direction: 'asc' as const },
        };

        render(<ResourcesPage {...props} />);

        const previousButton = screen.getByRole('button', { name: /previous/i });
        const nextButton = screen.getByRole('button', { name: /next/i });

        expect(previousButton).toBeEnabled();
        expect(nextButton).toBeEnabled();

        fireEvent.click(nextButton);
        expect(routerMock.get).toHaveBeenCalledWith(
            '/resources',
            { page: 3, per_page: 50, sort_key: 'id', sort_direction: 'asc' },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );

        fireEvent.click(previousButton);
        expect(routerMock.get).toHaveBeenCalledWith(
            '/resources',
            { page: 1, per_page: 50, sort_key: 'id', sort_direction: 'asc' },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
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

        // Delete functionality is not yet implemented - skip dialog test
        // fireEvent.click(screen.getByRole('button', { name: /delete.*placeholder title.*from ernie/i }));
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

    // Delete functionality is not yet implemented - test skipped
    it.skip('confirms destructive actions before deleting a resource', async () => {
        const resource = {
            id: 1,
            doi: '10.5555/example',
            year: 2022,
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

        const deleteButton = screen.getByRole('button', {
            name: /delete.*primary title.*from ernie/i,
        });

        await act(async () => {
            fireEvent.click(deleteButton);
            await Promise.resolve();
        });

        expect(
            screen.getByRole('dialog', {
                name: /delete.*primary title/i,
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/this will permanently remove/i)).toBeInTheDocument();

        const confirmButton = screen.getByRole('button', { name: /delete resource/i });

        expect(confirmButton).toBeEnabled();

        await act(async () => {
            fireEvent.click(confirmButton);
            await Promise.resolve();
        });

        expect(routerMock.delete).toHaveBeenCalledWith(
            '/resources/1',
            expect.objectContaining({
                preserveScroll: true,
            }),
        );

        expect(confirmButton).toBeDisabled();
        expect(confirmButton).toHaveAttribute('aria-busy', 'true');

        const [, options] = routerMock.delete.mock.calls.at(-1) ?? [];

        expect(options?.onSuccess).toBeTypeOf('function');
        expect(options?.onFinish).toBeTypeOf('function');

        await act(async () => {
            options?.onSuccess?.();
            options?.onFinish?.();
        });

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
