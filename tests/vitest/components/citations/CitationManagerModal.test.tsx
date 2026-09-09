import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { CitationManagerModal } from '@/components/citations/CitationManagerModal';

import { http, HttpResponse, server } from '../../helpers/msw-server';

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

const resourceTypes = [{ value: 'JournalArticle', label: 'Journal Article' }];
const relationTypes = [
    { id: 1, slug: 'Cites', label: 'Cites' },
    { id: 2, slug: 'IsSupplementTo', label: 'Is Supplement To' },
];
const contributorTypes = [{ value: 'Editor', label: 'Editor' }];

const resourceId = 99;
const base = `/resources/${resourceId}/related-items`;

const sampleItem = {
    id: 1,
    resource_id: resourceId,
    related_item_type: 'JournalArticle',
    relation_type_id: 1,
    relation_type_slug: 'Cites',
    publication_year: 2023,
    identifier: '10.1234/sample',
    identifier_type: 'DOI',
    position: 0,
    titles: [{ title: 'Sample paper', title_type: 'MainTitle', position: 0 }],
    creators: [],
    contributors: [],
};

function renderModal() {
    return render(
        <CitationManagerModal
            open
            onOpenChange={() => {}}
            resourceId={resourceId}
            resourceTypes={resourceTypes}
            relationTypes={relationTypes}
            contributorTypes={contributorTypes}
        />,
    );
}

describe('CitationManagerModal', () => {
    it('shows an empty state when no items exist', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [] })));
        renderModal();

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: /Related Item Manager/i })).toBeInTheDocument();
            expect(screen.getByText(/Manage DataCite related items for this resource/i)).toBeInTheDocument();
            expect(screen.getByText(/No related items yet/i)).toBeInTheDocument();
            expect(screen.getByText(/Add related resources with full metadata/i)).toBeInTheDocument();
        });
    });

    it('lists related items fetched from the backend', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [sampleItem] })));
        renderModal();

        await waitFor(() => {
            expect(screen.getByText(/Sample paper/)).toBeInTheDocument();
        });
        expect(screen.getByText('Cites')).toBeInTheDocument();
    });

    it('switches to the create form and back', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [] })));
        const user = userEvent.setup();
        renderModal();

        await waitFor(() =>
            expect(screen.getByText(/No related items yet/i)).toBeInTheDocument(),
        );

        await user.click(screen.getByRole('button', { name: /Add related item/i }));
        expect(screen.getByText('Type *')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /^Cancel$/ }));
        expect(screen.queryByText('Type *')).toBeNull();
    });

    it('opens a forthcoming publication form with stable preset values', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [] })));
        const user = userEvent.setup();
        renderModal();

        await waitFor(() => expect(screen.getByText(/No related items yet/i)).toBeInTheDocument());
        await user.click(screen.getByRole('button', { name: /Add forthcoming publication/i }));

        expect(screen.getByText(/identifier is optional and can be added to this item later/i)).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: /^Type \*$/i })).toHaveTextContent('Journal Article');
        expect(screen.getByRole('combobox', { name: /Relation type \*/i })).toHaveTextContent('Is Supplement To');
        expect(screen.getByPlaceholderText(/10\.1234\/abcd/)).toHaveValue('');
    });

    it('creates a forthcoming publication without sending a placeholder identifier', async () => {
        let payload: Record<string, unknown> | null = null;
        server.use(
            http.get(base, () => HttpResponse.json({ data: [] })),
            http.post(base, async ({ request }) => {
                payload = (await request.json()) as Record<string, unknown>;
                return HttpResponse.json({ data: { ...sampleItem, id: 2, ...payload } }, { status: 201 });
            }),
        );
        const user = userEvent.setup();
        renderModal();

        await waitFor(() => expect(screen.getByText(/No related items yet/i)).toBeInTheDocument());
        await user.click(screen.getByRole('button', { name: /Add forthcoming publication/i }));
        await user.type(screen.getByPlaceholderText('Title'), 'Paper to be published');
        await user.click(screen.getByRole('button', { name: /^Save$/ }));

        await waitFor(() => expect(payload).not.toBeNull());
        expect(payload).toMatchObject({
            related_item_type: 'JournalArticle',
            relation_type_id: 2,
            identifier: null,
            identifier_type: null,
        });
    });

    it('disables the forthcoming preset when IsSupplementTo is unavailable', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [] })));
        render(
            <CitationManagerModal
                open
                onOpenChange={() => {}}
                resourceId={resourceId}
                resourceTypes={resourceTypes}
                relationTypes={[{ id: 1, slug: 'Cites', label: 'Cites' }]}
                contributorTypes={contributorTypes}
            />,
        );

        const button = await screen.findByRole('button', { name: /Add forthcoming publication/i });
        expect(button).toBeDisabled();
        expect(button).toHaveAccessibleDescription(/required vocabularies are missing/i);
    });

    it('creates a new related item and returns to the list', async () => {
        let posted = false;
        server.use(
            http.get(base, () => HttpResponse.json({ data: [] })),
            http.post(base, async () => {
                posted = true;
                return HttpResponse.json({ data: { ...sampleItem, id: 2 } }, { status: 201 });
            }),
        );
        const user = userEvent.setup();
        renderModal();

        await waitFor(() =>
            expect(screen.getByText(/No related items yet/i)).toBeInTheDocument(),
        );
        await user.click(screen.getByRole('button', { name: /Add related item/i }));

        await user.type(
            screen.getByPlaceholderText('Title'),
            'New related',
        );

        // Select type
        await user.click(screen.getAllByRole('combobox')[0]);
        await user.click(screen.getByRole('option', { name: /Journal Article/i }));

        // Select relation
        await user.click(screen.getAllByRole('combobox')[1]);
        await user.click(screen.getByRole('option', { name: /^Cites$/i }));

        await user.click(screen.getByRole('button', { name: /^Save$/ }));

        await waitFor(() => expect(posted).toBe(true));
    });

    it('renders an error message when the fetch fails', async () => {
        server.use(
            http.get(base, () =>
                HttpResponse.json({ message: 'boom' }, { status: 500 }),
            ),
        );
        renderModal();

        await waitFor(() => {
            expect(screen.getByText(/boom|failed/i)).toBeInTheDocument();
        });
    });

    it('closes the modal when the Close button is clicked', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [] })));
        const onOpenChange = vi.fn();
        const user = userEvent.setup();
        render(
            <CitationManagerModal
                open
                onOpenChange={onOpenChange}
                resourceId={resourceId}
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText(/No related items yet/i)).toBeInTheDocument(),
        );
        // The Dialog wrapper exposes its own "Close" (X) button; pick the
        // footer text button which is the last one in DOM order.
        const closeButtons = screen.getAllByRole('button', { name: /^Close$/ });
        await user.click(closeButtons[closeButtons.length - 1]);

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('does not call DELETE when the confirmation dialog is cancelled', async () => {
        let deleted = false;
        server.use(
            http.get(base, () => HttpResponse.json({ data: [sampleItem] })),
            http.delete(`${base}/1`, () => {
                deleted = true;
                return new HttpResponse(null, { status: 204 });
            }),
        );
        const user = userEvent.setup();
        renderModal();

        await waitFor(() =>
            expect(screen.getByText(/Sample paper/)).toBeInTheDocument(),
        );

        await user.click(
            screen.getByRole('button', { name: /delete related item/i }),
        );

        const cancelBtn = await screen.findByRole('button', { name: /cancel/i });
        await user.click(cancelBtn);

        expect(deleted).toBe(false);
        expect(screen.getByText(/Sample paper/)).toBeInTheDocument();
    });

    it('calls DELETE and removes the item when the confirmation dialog is accepted', async () => {
        let deleted = false;
        server.use(
            http.get(base, () => HttpResponse.json({ data: [sampleItem] })),
            http.delete(`${base}/1`, () => {
                deleted = true;
                return new HttpResponse(null, { status: 204 });
            }),
        );
        const user = userEvent.setup();
        renderModal();

        await waitFor(() =>
            expect(screen.getByText(/Sample paper/)).toBeInTheDocument(),
        );

        await user.click(
            screen.getByRole('button', { name: /delete related item/i }),
        );

        const confirmBtn = await screen.findByRole('button', { name: /^delete$/i });
        await user.click(confirmBtn);

        await waitFor(() => expect(deleted).toBe(true));
        await waitFor(() =>
            expect(screen.queryByText(/Sample paper/)).toBeNull(),
        );
    });

    it('opens the edit form with pre-filled title', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [sampleItem] })));
        const user = userEvent.setup();
        renderModal();

        await waitFor(() =>
            expect(screen.getByText(/Sample paper/)).toBeInTheDocument(),
        );

        await user.click(screen.getByRole('button', { name: /edit/i }));

        expect(screen.getByDisplayValue('Sample paper')).toBeInTheDocument();
    });
});
