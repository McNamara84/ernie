import '@testing-library/jest-dom/vitest';

import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkField from '@/components/curation/fields/related-work/related-work-field';
import type { RelatedIdentifier } from '@/types';

vi.mock('@/actions/App/Http/Controllers/Api/RelatedIdentifierCitationLabelController', () => ({
    resolve: {
        url: vi.fn(
            ({ query }: { query: { identifier: string; identifierType: string } }) =>
                `/api/v1/related-identifiers/citation-label?identifier=${encodeURIComponent(query.identifier)}&identifierType=${query.identifierType}`,
        ),
    },
}));

vi.mock('@/components/curation/fields/related-work/related-work-csv-import', () => ({
    default: ({
        onImport,
        onClose,
    }: {
        onImport: (data: { identifier: string; identifierType: string; relationType: string }[]) => void;
        onClose: () => void;
    }) => (
        <div data-testid="csv-import">
            <button
                type="button"
                data-testid="csv-import-submit"
                onClick={() =>
                    onImport([
                        { identifier: '10.1234/csv1', identifierType: 'DOI', relationType: 'Cites' },
                        { identifier: 'https://example.org/csv2', identifierType: 'URL', relationType: 'References' },
                    ])
                }
            >
                Import
            </button>
            <button type="button" data-testid="csv-import-close" onClick={onClose}>
                Close
            </button>
        </div>
    ),
}));

vi.mock('@/components/curation/fields/related-work/related-work-list', () => ({
    default: ({
        items,
        completedItemCount,
        onRemove,
        onItemChange,
        onIdentifierBlur,
        onReorder,
        citationResolutionStates,
    }: {
        items: RelatedIdentifier[];
        completedItemCount: number;
        onRemove: (index: number) => void;
        onItemChange: (index: number, item: RelatedIdentifier) => void;
        onIdentifierBlur: (index: number) => void;
        onReorder: (items: RelatedIdentifier[]) => void;
        citationResolutionStates?: Map<number, { status: string; message?: string }>;
    }) => (
        <div data-testid="related-work-list">
            <span data-testid="completed-count">{completedItemCount}</span>
            {items.map((item, index) => (
                <div key={index} data-testid={`item-${index}`}>
                    <input
                        data-testid={`item-identifier-${index}`}
                        value={item.identifier}
                        onChange={(event) => onItemChange(index, { ...item, identifier: event.target.value })}
                        onBlur={() => onIdentifierBlur(index)}
                    />
                    <input
                        aria-label={`Citation label ${index + 1}`}
                        value={item.citation_label ?? ''}
                        onChange={(event) => onItemChange(index, { ...item, citation_label: event.target.value })}
                    />
                    <span data-testid={`identifier-type-${index}`}>{item.identifier_type}</span>
                    <span data-testid={`relation-type-${index}`}>{item.relation_type}</span>
                    <span data-testid={`position-${index}`}>{item.position}</span>
                    <span data-testid={`resolution-status-${index}`}>{citationResolutionStates?.get(index)?.status ?? ''}</span>
                    <button type="button" data-testid={`set-url-${index}`} onClick={() => onItemChange(index, { ...item, identifier_type: 'URL' })}>
                        Set URL
                    </button>
                    <button
                        type="button"
                        data-testid={`set-references-${index}`}
                        onClick={() => onItemChange(index, { ...item, relation_type: 'References' })}
                    >
                        Set References
                    </button>
                    <button
                        type="button"
                        data-testid={`manual-citation-${index}`}
                        onClick={() => onItemChange(index, { ...item, citation_label: 'Manually curated citation' })}
                    >
                        Set manual citation
                    </button>
                    <button type="button" data-testid={`remove-${index}`} onClick={() => onRemove(index)}>
                        Remove
                    </button>
                </div>
            ))}
            {items.length > 1 && (
                <button type="button" data-testid="reorder-items" onClick={() => onReorder([...items].reverse())}>
                    Reorder
                </button>
            )}
        </div>
    ),
}));

function StatefulField({
    initialItems = [],
    activeIdentifierTypes,
    activeRelationTypes,
}: {
    initialItems?: RelatedIdentifier[];
    activeIdentifierTypes?: string[];
    activeRelationTypes?: string[];
}) {
    const [items, setItems] = useState(initialItems);

    return (
        <RelatedWorkField
            relatedWorks={items}
            onChange={setItems}
            activeIdentifierTypes={activeIdentifierTypes}
            activeRelationTypes={activeRelationTypes}
        />
    );
}

async function addFirstCard(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole('button', { name: /^add related work$/i }));
}

async function flushLookup() {
    await act(async () => {
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
    });
}

describe('RelatedWorkField', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            json: vi.fn().mockResolvedValue({}),
        }) as unknown as typeof fetch;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the empty state without creating a related work', () => {
        const onChange = vi.fn();

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        expect(screen.getByTestId('related-work-empty-state')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^add related work$/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^import csv$/i })).toBeInTheDocument();
        expect(screen.queryByTestId('related-work-list')).not.toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
    });

    it('creates a complete empty card immediately without a second add action', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<StatefulField />);
        await addFirstCard(user);

        expect(screen.queryByTestId('related-work-empty-state')).not.toBeInTheDocument();
        expect(screen.getByTestId('related-work-list')).toBeInTheDocument();
        expect(screen.getByTestId('item-identifier-0')).toHaveValue('');
        expect(screen.getByLabelText('Citation label 1')).toBeInTheDocument();
        expect(screen.getByTestId('identifier-type-0')).toHaveTextContent('DOI');
        expect(screen.getByTestId('relation-type-0')).toHaveTextContent('Cites');
        expect(screen.getByTestId('completed-count')).toHaveTextContent('0');
        expect(screen.getByRole('button', { name: /^add related work$/i })).toBeDisabled();
        expect(screen.queryByTestId('add-related-work-button')).not.toBeInTheDocument();
    });

    it('uses the first active options when the preferred defaults are inactive', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<StatefulField activeIdentifierTypes={['URL']} activeRelationTypes={['References']} />);
        await addFirstCard(user);

        expect(screen.getByTestId('identifier-type-0')).toHaveTextContent('URL');
        expect(screen.getByTestId('relation-type-0')).toHaveTextContent('References');
    });

    it('allows another card only after the current card has an identifier', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<StatefulField />);
        await addFirstCard(user);

        const addButton = screen.getByRole('button', { name: /^add related work$/i });
        expect(addButton).toBeDisabled();

        await user.type(screen.getByTestId('item-identifier-0'), '10.1234/first');
        expect(addButton).toBeEnabled();
        await user.click(addButton);

        expect(screen.getByTestId('item-1')).toBeInTheDocument();
        expect(screen.getByTestId('item-identifier-1')).toHaveValue('');
        expect(screen.getByTestId('completed-count')).toHaveTextContent('1');
        expect(addButton).toBeDisabled();
    });

    it('returns to the empty state after removing the final card', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<StatefulField />);
        await addFirstCard(user);
        await user.click(screen.getByTestId('remove-0'));

        expect(screen.getByTestId('related-work-empty-state')).toBeInTheDocument();
        expect(screen.queryByTestId('related-work-list')).not.toBeInTheDocument();
    });

    it('normalizes a DOI and resolves its citation label on identifier blur', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue({ citation: 'Doe, J. (2026). Resolved DOI citation.' }),
        }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), 'https://doi.org/10.5880/GFZ.TEST');
        await user.tab();
        await flushLookup();

        expect(screen.getByTestId('item-identifier-0')).toHaveValue('10.5880/gfz.test');
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('identifier=10.5880%2Fgfz.test&identifierType=DOI'),
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Doe, J. (2026). Resolved DOI citation.');
        expect(screen.getByTestId('resolution-status-0')).toHaveTextContent('resolved');
    });

    it('resolves an exact cached URL citation on identifier blur', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue({ citation: 'Legacy URL citation' }),
        }) as unknown as typeof fetch;

        render(<StatefulField activeIdentifierTypes={['URL']} />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), 'https://example.org/exact-resource');
        await user.tab();
        await flushLookup();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('identifier=https%3A%2F%2Fexample.org%2Fexact-resource&identifierType=URL'),
            expect.any(Object),
        );
        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Legacy URL citation');
    });

    it('does not resolve unsupported or invalid identifiers', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <StatefulField
                initialItems={[
                    { identifier: '978-3-16-148410-0', identifier_type: 'ISBN', relation_type: 'References', position: 0 },
                    { identifier: 'invalid DOI', identifier_type: 'DOI', relation_type: 'Cites', position: 1 },
                    { identifier: 'javascript:alert(1)', identifier_type: 'URL', relation_type: 'References', position: 2 },
                ]}
            />,
        );

        await user.click(screen.getByTestId('item-identifier-0'));
        await user.tab();
        await user.click(screen.getByTestId('item-identifier-1'));
        await user.tab();
        await user.click(screen.getByTestId('item-identifier-2'));
        await user.tab();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(screen.getByTestId('resolution-status-1')).toHaveTextContent('unavailable');
        expect(screen.getByTestId('resolution-status-2')).toHaveTextContent('unavailable');
    });

    it('never overwrites a manually curated citation label', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue({ citation: 'Automatically resolved citation' }),
        }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/manual');
        await user.click(screen.getByTestId('manual-citation-0'));
        await flushLookup();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Manually curated citation');
    });

    it('caches authoritative not-found lookups for repeated blur events', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 404,
            json: vi.fn().mockResolvedValue({ error: 'No citation label could be resolved for this identifier.' }),
        }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/not-found');
        await user.tab();
        await flushLookup();
        await user.click(screen.getByTestId('item-identifier-0'));
        await user.tab();
        await flushLookup();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(screen.getByTestId('resolution-status-0')).toHaveTextContent('unavailable');
    });

    it.each([429, 503])('retries a citation lookup after a transient HTTP %i response', async (status) => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi
            .fn()
            .mockResolvedValueOnce({
                ok: false,
                status,
                json: vi.fn().mockResolvedValue({ message: 'Temporarily unavailable.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: vi.fn().mockResolvedValue({ citation: 'Citation resolved after retry' }),
            }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/retry-http');
        await user.tab();
        await flushLookup();

        expect(screen.getByTestId('resolution-status-0')).toHaveTextContent('unavailable');

        await user.click(screen.getByTestId('item-identifier-0'));
        await user.tab();
        await flushLookup();

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Citation resolved after retry');
        expect(screen.getByTestId('resolution-status-0')).toHaveTextContent('resolved');
    });

    it('retries a citation lookup after a network failure', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi
            .fn()
            .mockRejectedValueOnce(new TypeError('Network request failed'))
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: vi.fn().mockResolvedValue({ citation: 'Citation resolved after reconnecting' }),
            }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/retry-network');
        await user.tab();
        await flushLookup();

        expect(screen.getByTestId('resolution-status-0')).toHaveTextContent('unavailable');

        await user.click(screen.getByTestId('item-identifier-0'));
        await user.tab();
        await flushLookup();

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Citation resolved after reconnecting');
        expect(screen.getByTestId('resolution-status-0')).toHaveTextContent('resolved');
    });

    it('reuses successful lookups without another request', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue({ citation: 'Cached citation' }),
        }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/cached');
        await user.tab();
        await flushLookup();
        await user.clear(screen.getByLabelText('Citation label 1'));
        await user.click(screen.getByTestId('item-identifier-0'));
        await user.tab();
        await flushLookup();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Cached citation');
    });

    it('preserves a citation label entered while a lookup is pending', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        let resolveJson: ((value: { citation: string }) => void) | undefined;

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn(
                () =>
                    new Promise<{ citation: string }>((resolve) => {
                        resolveJson = resolve;
                    }),
            ),
        }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/pending-manual');
        await user.tab();
        await user.click(screen.getByTestId('manual-citation-0'));

        await act(async () => {
            resolveJson?.({ citation: 'Late automatic citation' });
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Manually curated citation');
    });

    it('does not apply a delayed lookup after its card was removed', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        let resolveJson: ((value: { citation: string }) => void) | undefined;

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn(
                () =>
                    new Promise<{ citation: string }>((resolve) => {
                        resolveJson = resolve;
                    }),
            ),
        }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/pending');
        await user.tab();
        await user.click(screen.getByTestId('remove-0'));

        await act(async () => {
            resolveJson?.({ citation: 'Stale citation' });
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(screen.getByTestId('related-work-empty-state')).toBeInTheDocument();
        expect(screen.queryByDisplayValue('Stale citation')).not.toBeInTheDocument();
    });

    it('does not apply a delayed lookup after the identifier changes', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        let resolveJson: ((value: { citation: string }) => void) | undefined;

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn(
                () =>
                    new Promise<{ citation: string }>((resolve) => {
                        resolveJson = resolve;
                    }),
            ),
        }) as unknown as typeof fetch;

        render(<StatefulField />);
        await addFirstCard(user);
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/original');
        await user.tab();
        await user.clear(screen.getByTestId('item-identifier-0'));
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/replacement');

        await act(async () => {
            resolveJson?.({ citation: 'Citation for original identifier' });
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(screen.getByLabelText('Citation label 1')).toHaveValue('');
    });

    it('preserves non-identifier metadata while clearing stale resolved data after an identifier change', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <StatefulField
                initialItems={[
                    {
                        identifier: '10.5880/original',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        citation_label: 'Old citation',
                        related_title: 'Old title',
                        related_metadata: { publisher: 'GFZ' },
                        source: 'relation_suggestion_assistant',
                        position: 0,
                    },
                ]}
            />,
        );

        await user.clear(screen.getByTestId('item-identifier-0'));
        await user.type(screen.getByTestId('item-identifier-0'), '10.5880/updated');

        expect(screen.getByLabelText('Citation label 1')).toHaveValue('');
        expect(screen.getByTestId('item-0')).toBeInTheDocument();
    });

    it('rejects exact duplicates but allows the same identifier with another relation type', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <StatefulField
                initialItems={[
                    { identifier: '10.5880/one', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
                    { identifier: '10.5880/two', identifier_type: 'DOI', relation_type: 'References', position: 1 },
                ]}
            />,
        );

        await user.clear(screen.getByTestId('item-identifier-1'));
        await user.type(screen.getByTestId('item-identifier-1'), '10.5880/one');
        expect(screen.queryByText(/this exact relation already exists/i)).not.toBeInTheDocument();

        await user.click(screen.getByTestId('set-references-0'));
        expect(screen.getByText(/this exact relation already exists/i)).toBeInTheDocument();
        expect(screen.getByTestId('relation-type-0')).toHaveTextContent('Cites');
    });

    it('does not show an opposite-relation suggestion after a relation type change (Issue #1293)', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <StatefulField
                initialItems={[{ identifier: '10.5880/one', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
            />,
        );

        await user.click(screen.getByTestId('set-references-0'));

        expect(screen.getByTestId('relation-type-0')).toHaveTextContent('References');
        expect(screen.queryByText(/Did you mean/i)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Use /i })).not.toBeInTheDocument();
    });

    it('reindexes cards after removal and reorder', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <StatefulField
                initialItems={[
                    { identifier: '10.5880/a', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
                    { identifier: '10.5880/b', identifier_type: 'DOI', relation_type: 'References', position: 1 },
                    { identifier: '10.5880/c', identifier_type: 'DOI', relation_type: 'Documents', position: 2 },
                ]}
            />,
        );

        await user.click(screen.getByTestId('remove-1'));
        expect(screen.getByTestId('item-identifier-1')).toHaveValue('10.5880/c');
        expect(screen.getByTestId('position-1')).toHaveTextContent('1');

        await user.click(screen.getByTestId('reorder-items'));
        expect(screen.getByTestId('item-identifier-0')).toHaveValue('10.5880/c');
        expect(screen.getByTestId('position-0')).toHaveTextContent('0');
    });

    it('opens and closes CSV import without discarding an existing empty card', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<StatefulField />);
        await addFirstCard(user);
        await user.click(screen.getByRole('button', { name: /import from csv/i }));

        expect(screen.getByTestId('csv-import')).toBeInTheDocument();
        expect(screen.queryByTestId('related-work-list')).not.toBeInTheDocument();

        await user.click(screen.getByTestId('csv-import-close'));
        expect(screen.getByTestId('item-identifier-0')).toHaveValue('');
    });

    it('preserves an empty card while appending and positioning CSV rows', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<StatefulField />);
        await addFirstCard(user);
        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));
        await flushLookup();

        expect(screen.getByTestId('item-identifier-0')).toHaveValue('');
        expect(screen.getByTestId('item-identifier-1')).toHaveValue('10.1234/csv1');
        expect(screen.getByTestId('item-identifier-2')).toHaveValue('https://example.org/csv2');
        expect(screen.getByTestId('position-0')).toHaveTextContent('0');
        expect(screen.getByTestId('position-1')).toHaveTextContent('1');
        expect(screen.getByTestId('position-2')).toHaveTextContent('2');
    });

    it('imports DOI and URL rows and hydrates both supported citation types', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi
            .fn()
            .mockResolvedValueOnce({ ok: true, json: vi.fn().mockResolvedValue({ citation: 'Imported DOI citation' }) })
            .mockResolvedValueOnce({ ok: true, json: vi.fn().mockResolvedValue({ citation: 'Imported URL citation' }) }) as unknown as typeof fetch;

        render(<StatefulField />);
        await user.click(screen.getByRole('button', { name: /^import csv$/i }));
        await user.click(screen.getByTestId('csv-import-submit'));
        await flushLookup();

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('Citation label 1')).toHaveValue('Imported DOI citation');
        expect(screen.getByLabelText('Citation label 2')).toHaveValue('Imported URL citation');
    });

    it('skips duplicate CSV rows and clears the warning after eight seconds', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <StatefulField
                initialItems={[{ identifier: '10.1234/csv1', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
            />,
        );

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));

        expect(screen.getByText(/skipped 1 duplicate/i)).toBeInTheDocument();
        expect(screen.getByTestId('item-identifier-1')).toHaveValue('https://example.org/csv2');

        act(() => vi.advanceTimersByTime(8000));
        expect(screen.queryByText(/skipped 1 duplicate/i)).not.toBeInTheDocument();
    });
});
