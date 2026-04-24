import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { RelatedItemForm } from '@/components/citations/RelatedItemForm';

import { http, HttpResponse, server } from '../../helpers/msw-server';

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const resourceTypes = [
    { value: 'JournalArticle', label: 'Journal Article' },
    { value: 'Book', label: 'Book' },
];
const relationTypes = [
    { id: 1, label: 'Cites' },
    { id: 2, label: 'IsSupplementTo' },
];
const contributorTypes = [{ value: 'Editor', label: 'Editor' }];

describe('RelatedItemForm', () => {
    it('renders required field labels and all accordion sections', () => {
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        expect(screen.getByText('Type *')).toBeInTheDocument();
        expect(screen.getByText('Relation type *')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Titles \*/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Creators/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Contributors/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Publication details/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Numbering/ })).toBeInTheDocument();
    });

    it('shows validation errors when submitting empty form', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={onSubmit}
            />,
        );

        await user.click(screen.getByRole('button', { name: /^Save$/ }));

        await waitFor(() => {
            expect(screen.getByText(/Related item type is required/i)).toBeInTheDocument();
        });
        expect(screen.getByText(/Relation type is required/i)).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('calls onSubmit with valid values', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn().mockResolvedValue(undefined);
        render(
            <RelatedItemForm
                initialValue={{
                    related_item_type: 'JournalArticle',
                    relation_type_id: 1,
                    titles: [{ title: 'My title', title_type: 'MainTitle', position: 0 }],
                    position: 0,
                }}
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={onSubmit}
            />,
        );

        await user.click(screen.getByRole('button', { name: /^Save$/ }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        const payload = onSubmit.mock.calls[0][0];
        expect(payload.related_item_type).toBe('JournalArticle');
        expect(payload.relation_type_id).toBe(1);
        expect(payload.titles[0].title).toBe('My title');
    });

    it('autofills metadata when the Lookup button is clicked', async () => {
        server.use(
            http.get('/api/v1/citation-lookup', () =>
                HttpResponse.json({
                    source: 'crossref',
                    identifier: '10.1234/x',
                    identifier_type: 'DOI',
                    related_item_type: 'JournalArticle',
                    title: 'Autofilled Title',
                    publication_year: 2024,
                    publisher: 'ACME',
                    volume: '5',
                    creators: [
                        {
                            name: 'Doe, Jane',
                            name_type: 'Personal',
                            given_name: 'Jane',
                            family_name: 'Doe',
                        },
                    ],
                }),
            ),
        );

        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        const identifierInput = screen.getByPlaceholderText(/10\.1234\/abcd/);
        await user.type(identifierInput, '10.1234/x');
        await user.click(screen.getByRole('button', { name: /Look up DOI metadata/i }));

        await waitFor(
            () => {
                expect(screen.getByText(/Metadata autofilled from crossref/i)).toBeInTheDocument();
            },
            { timeout: 2000 },
        );

        // Title field should now contain the autofilled value
        expect(screen.getByDisplayValue('Autofilled Title')).toBeInTheDocument();
        // Open publication details to reveal publisher input
        await user.click(screen.getByRole('button', { name: /Publication details/ }));
        expect(screen.getByDisplayValue('ACME')).toBeInTheDocument();
    });

    it('invokes onCancel when the Cancel button is clicked', async () => {
        const user = userEvent.setup();
        const onCancel = vi.fn();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
                onCancel={onCancel}
            />,
        );

        await user.click(screen.getByRole('button', { name: /^Cancel$/ }));
        expect(onCancel).toHaveBeenCalled();
    });
});

describe('RelatedItemForm — lookup edge cases', () => {
    it('shows "No metadata found" message when source is not_found', async () => {
        server.use(
            http.get('/api/v1/citation-lookup', () =>
                HttpResponse.json({ source: 'not_found' }),
            ),
        );

        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        await user.type(
            screen.getByPlaceholderText(/10\.1234\/abcd/),
            '10.9999/missing',
        );
        await user.click(screen.getByRole('button', { name: /Look up DOI metadata/i }));

        await waitFor(() => {
            expect(
                screen.getByText(/No metadata found for this identifier/i),
            ).toBeInTheDocument();
        });
    });

    it('renders lookup error as destructive message', async () => {
        server.use(
            http.get('/api/v1/citation-lookup', () =>
                HttpResponse.json({ message: 'Crossref timed out' }, { status: 500 }),
            ),
        );

        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        await user.type(
            screen.getByPlaceholderText(/10\.1234\/abcd/),
            '10.1234/boom',
        );
        await user.click(screen.getByRole('button', { name: /Look up DOI metadata/i }));

        await waitFor(() => {
            expect(screen.getByText(/Crossref timed out|failed/i)).toBeInTheDocument();
        });
    });

    it('does not call the API when identifier field is empty', async () => {
        let called = false;
        server.use(
            http.get('/api/v1/citation-lookup', () => {
                called = true;
                return HttpResponse.json({ source: 'not_found' });
            }),
        );

        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        await user.click(screen.getByRole('button', { name: /Look up DOI metadata/i }));

        // Wait briefly for any request to have fired
        await new Promise((r) => setTimeout(r, 50));
        expect(called).toBe(false);
    });

    it('hides the Lookup button when enableLookup is false', () => {
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
                enableLookup={false}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Look up DOI metadata/i }),
        ).toBeNull();
    });

    it('also autofills the subtitle when provided', async () => {
        server.use(
            http.get('/api/v1/citation-lookup', () =>
                HttpResponse.json({
                    source: 'crossref',
                    identifier: '10.1234/x',
                    identifier_type: 'DOI',
                    related_item_type: 'JournalArticle',
                    title: 'Main Autofilled',
                    subtitle: 'An Autofilled Subtitle',
                    publication_year: 2024,
                }),
            ),
        );

        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        await user.type(
            screen.getByPlaceholderText(/10\.1234\/abcd/),
            '10.1234/x',
        );
        await user.click(screen.getByRole('button', { name: /Look up DOI metadata/i }));

        await waitFor(() => {
            expect(screen.getByDisplayValue('Main Autofilled')).toBeInTheDocument();
        });
        expect(screen.getByDisplayValue('An Autofilled Subtitle')).toBeInTheDocument();
    });

    it('autofills an organizational creator', async () => {
        server.use(
            http.get('/api/v1/citation-lookup', () =>
                HttpResponse.json({
                    source: 'datacite',
                    identifier: '10.1234/org',
                    identifier_type: 'DOI',
                    related_item_type: 'Book',
                    title: 'Org Book',
                    publication_year: 2020,
                    creators: [
                        {
                            name: 'GFZ Helmholtz Centre',
                            name_type: 'Organizational',
                        },
                    ],
                }),
            ),
        );

        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        await user.type(
            screen.getByPlaceholderText(/10\.1234\/abcd/),
            '10.1234/org',
        );
        await user.click(screen.getByRole('button', { name: /Look up DOI metadata/i }));

        // Wait until the autofill effect has populated the creators field array
        // (accordion header text reflects the count).
        const creatorsAccordion = await screen.findByRole('button', {
            name: /Creators \(1\)/,
        });
        await user.click(creatorsAccordion);

        await waitFor(() => {
            expect(
                screen.getByDisplayValue('GFZ Helmholtz Centre'),
            ).toBeInTheDocument();
        });
    });
});

describe('RelatedItemForm — titles field array', () => {
    it('disables the remove button when only one title remains', () => {
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        const removeBtn = screen.getByRole('button', { name: /Remove title 1/i });
        expect(removeBtn).toBeDisabled();
    });

    it('adds and removes additional titles', async () => {
        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        await user.click(screen.getByRole('button', { name: /Add title/i }));

        // Now there are two titles → remove buttons both enabled
        const removeButtons = screen.getAllByRole('button', {
            name: /Remove title \d+/i,
        });
        expect(removeButtons).toHaveLength(2);
        expect(removeButtons[0]).not.toBeDisabled();

        await user.click(removeButtons[1]);

        await waitFor(() => {
            expect(
                screen.getAllByRole('button', { name: /Remove title \d+/i }),
            ).toHaveLength(1);
        });
    });

    it('prefills all fields from initialValue in edit mode', () => {
        render(
            <RelatedItemForm
                initialValue={{
                    related_item_type: 'Book',
                    relation_type_id: 2,
                    publisher: 'Springer',
                    publication_year: 2019,
                    titles: [
                        { title: 'Existing Main', title_type: 'MainTitle', position: 0 },
                        { title: 'Existing Sub', title_type: 'Subtitle', position: 1 },
                    ],
                    position: 0,
                }}
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        expect(screen.getByDisplayValue('Existing Main')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Existing Sub')).toBeInTheDocument();
    });
});
