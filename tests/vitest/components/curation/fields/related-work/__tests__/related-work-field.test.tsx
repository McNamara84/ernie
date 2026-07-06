import '@testing-library/jest-dom/vitest';

import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkField from '@/components/curation/fields/related-work/related-work-field';
import { detectIdentifierType } from '@/lib/identifier-type-detection';
import type { RelatedIdentifier } from '@/types';

vi.mock('@/actions/App/Http/Controllers/Api/DataCiteController', () => ({
    getCitation: {
        url: vi.fn(() => '/api/datacite/citation'),
    },
}));

vi.mock('@/components/curation/fields/related-work/related-work-quick-add', () => ({
    default: ({
        onAdd,
        identifier,
        onIdentifierChange,
        identifierType,
        onIdentifierTypeChange,
        relationType,
        onRelationTypeChange,
    }: {
        onAdd: (data: { identifier: string; identifierType: string; relationType: string; citationLabel?: string }) => void;
        identifier: string;
        onIdentifierChange: (val: string) => void;
        identifierType: string;
        onIdentifierTypeChange: (val: string) => void;
        relationType: string;
        onRelationTypeChange: (val: string) => void;
    }) => (
        <div data-testid="quick-add">
            <input data-testid="identifier-input" value={identifier} onChange={(event) => onIdentifierChange(event.target.value)} />
            <button data-testid="set-url-type" onClick={() => onIdentifierTypeChange('URL')}>
                Set URL type
            </button>
            <button data-testid="set-references" onClick={() => onRelationTypeChange('References')}>
                Set References
            </button>
            <button data-testid="set-cites" onClick={() => onRelationTypeChange('Cites')}>
                Set Cites
            </button>
            <button data-testid="add-button" onClick={() => onAdd({ identifier, identifierType, relationType })}>
                Add
            </button>
            <button
                data-testid="add-with-manual-citation"
                onClick={() => onAdd({ identifier, identifierType, relationType, citationLabel: 'Manual citation from add form' })}
            >
                Add with citation
            </button>
            <span data-testid="identifier-type">{identifierType}</span>
            <span data-testid="relation-type">{relationType}</span>
        </div>
    ),
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
                data-testid="csv-import-submit"
                onClick={() =>
                    onImport([
                        { identifier: '10.1234/csv1', identifierType: 'DOI', relationType: 'Cites' },
                        { identifier: '10.1234/csv2', identifierType: 'DOI', relationType: 'References' },
                    ])
                }
            >
                Import
            </button>
            <button data-testid="csv-import-close" onClick={onClose}>
                Close
            </button>
        </div>
    ),
}));

vi.mock('@/components/curation/fields/related-work/related-work-list', () => ({
    default: ({
        items,
        onRemove,
        onItemChange,
        onReorder,
    }: {
        items: RelatedIdentifier[];
        onRemove: (index: number) => void;
        onItemChange: (index: number, item: RelatedIdentifier) => void;
        onReorder: (items: RelatedIdentifier[]) => void;
    }) => (
        <div data-testid="related-work-list">
            {items.map((item, index) => (
                <div key={`${item.identifier}-${item.relation_type}`} data-testid={`item-${index}`}>
                    <span>{item.identifier}</span>
                    <span>{item.relation_type}</span>
                    <span>{item.citation_label ?? ''}</span>
                    <button data-testid={`remove-${index}`} onClick={() => onRemove(index)}>
                        Remove
                    </button>
                </div>
            ))}
            {items[0] && (
                <>
                    <button
                        data-testid="edit-first-item"
                        onClick={() => onItemChange(0, { ...items[0], identifier: '10.1234/updated', citation_label: 'Old citation' })}
                    >
                        Edit first
                    </button>
                    <button
                        data-testid="edit-first-item-preserve-label"
                        onClick={() => onItemChange(0, { ...items[0], citation_label: 'Updated citation without identifier change' })}
                    >
                        Edit first label only
                    </button>
                    <button
                        data-testid="edit-first-item-type"
                        onClick={() => onItemChange(0, { ...items[0], identifier_type: 'URL', citation_label: 'Old citation' })}
                    >
                        Edit first type
                    </button>
                </>
            )}
            {items.length > 1 && (
                <>
                    <button
                        data-testid="edit-first-item-to-duplicate"
                        onClick={() =>
                            onItemChange(0, {
                                ...items[0],
                                identifier: items[1].identifier,
                                identifier_type: items[1].identifier_type,
                                relation_type: items[1].relation_type,
                            })
                        }
                    >
                        Edit first to duplicate
                    </button>
                    <button
                        data-testid="reorder-items"
                        onClick={() =>
                            onReorder(
                                [...items].reverse().map((item, index) => ({
                                    ...item,
                                    position: index,
                                })),
                            )
                        }
                    >
                        Reorder
                    </button>
                </>
            )}
        </div>
    ),
}));

vi.mock('@/lib/identifier-type-detection', () => ({
    detectIdentifierType: vi.fn(() => 'DOI'),
}));

const mockDetectIdentifierType = vi.mocked(detectIdentifierType);

describe('RelatedWorkField', () => {
    let onChange = vi.fn<(relatedWorks: RelatedIdentifier[]) => void>();

    beforeEach(() => {
        onChange = vi.fn<(relatedWorks: RelatedIdentifier[]) => void>();
        mockDetectIdentifierType.mockReturnValue('DOI');
        vi.useFakeTimers({ shouldAdvanceTime: true });
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            json: vi.fn().mockResolvedValue({}),
        }) as unknown as typeof fetch;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the add form by default', () => {
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        expect(screen.getByTestId('quick-add')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /import from csv/i })).toBeInTheDocument();
    });

    it('renders the list when related works are provided', () => {
        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/test', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        expect(screen.getByTestId('related-work-list')).toBeInTheDocument();
        expect(screen.getByText('10.1234/test')).toBeInTheDocument();
    });

    it('adds a new related work via the add form', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.type(screen.getByTestId('identifier-input'), '10.1234/new');
        await user.click(screen.getByTestId('add-button'));

        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier: '10.1234/new',
                identifier_type: 'DOI',
                relation_type: 'Cites',
                position: 0,
            }),
        ]);
    });

    it('auto-detects the identifier type while typing into the shared form', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        mockDetectIdentifierType.mockReturnValue('URL');

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.type(screen.getByTestId('identifier-input'), 'https://example.org/resource');

        expect(screen.getByTestId('identifier-type')).toHaveTextContent('URL');
    });

    it('preserves a manually overridden identifier type while the field continues to change', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        mockDetectIdentifierType.mockReturnValue('DOI');

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.click(screen.getByTestId('set-url-type'));
        await user.type(screen.getByTestId('identifier-input'), 'ambiguous-identifier');

        expect(screen.getByTestId('identifier-type')).toHaveTextContent('URL');
    });

    it('re-enables identifier auto-detection after the shared field is reset', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        mockDetectIdentifierType.mockReturnValueOnce('DOI').mockReturnValue('URL');

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.click(screen.getByTestId('set-url-type'));
        await user.type(screen.getByTestId('identifier-input'), 'ambiguous-identifier');
        await user.clear(screen.getByTestId('identifier-input'));
        await user.type(screen.getByTestId('identifier-input'), 'https://example.org/resource');

        expect(screen.getByTestId('identifier-type')).toHaveTextContent('URL');
    });

    it('initializes and resets add-form selections from active options when defaults are inactive', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} activeIdentifierTypes={['URL']} activeRelationTypes={['References']} />);

        expect(screen.getByTestId('identifier-type')).toHaveTextContent('URL');
        expect(screen.getByTestId('relation-type')).toHaveTextContent('References');

        await user.type(screen.getByTestId('identifier-input'), 'https://example.org/reference');
        await user.click(screen.getByTestId('add-button'));

        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier: 'https://example.org/reference',
                identifier_type: 'URL',
                relation_type: 'References',
                position: 0,
            }),
        ]);
        expect(screen.getByTestId('identifier-input')).toHaveValue('');
        expect(screen.getByTestId('identifier-type')).toHaveTextContent('URL');
        expect(screen.getByTestId('relation-type')).toHaveTextContent('References');
    });

    it('keeps auto-detected identifier types within the active options', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        mockDetectIdentifierType.mockReturnValue('DOI');

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} activeIdentifierTypes={['URL']} />);

        await user.type(screen.getByTestId('identifier-input'), '10.5880/inactive-doi');

        expect(screen.getByTestId('identifier-type')).toHaveTextContent('URL');
    });

    it('hydrates a citation label after adding a DOI when lookup succeeds', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue({ citation: 'Doe, J. (2024). Fetched Citation.' }),
        }) as unknown as typeof fetch;

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.type(screen.getByTestId('identifier-input'), '10.1234/new');
        await user.click(screen.getByTestId('add-button'));

        await act(async () => {
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(onChange).toHaveBeenLastCalledWith([
            expect.objectContaining({
                identifier: '10.1234/new',
                citation_label: 'Doe, J. (2024). Fetched Citation.',
            }),
        ]);
    });

    it('ignores empty citation payloads from the lookup endpoint', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue({ citation: '   ' }),
        }) as unknown as typeof fetch;

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.type(screen.getByTestId('identifier-input'), '10.1234/new');
        await user.click(screen.getByTestId('add-button'));

        await act(async () => {
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(onChange).toHaveBeenCalledTimes(1);
    });

    it('hydrates newly added citations without mutating unrelated existing items', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue({ citation: 'Doe, J. (2024). Fetched Citation.' }),
        }) as unknown as typeof fetch;

        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/existing', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        await user.type(screen.getByTestId('identifier-input'), '10.1234/new');
        await user.click(screen.getByTestId('set-references'));
        await user.click(screen.getByTestId('add-button'));

        await act(async () => {
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(onChange).toHaveBeenLastCalledWith([
            { identifier: '10.1234/existing', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            expect.objectContaining({
                identifier: '10.1234/new',
                relation_type: 'References',
                citation_label: 'Doe, J. (2024). Fetched Citation.',
            }),
        ]);
    });

    it('prevents duplicates with the same relation type', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/dup', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        await user.type(screen.getByTestId('identifier-input'), '10.1234/dup');
        await user.click(screen.getByTestId('add-button'));

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByText(/this exact relation already exists/i)).toBeInTheDocument();
    });

    it('treats DOI URLs as duplicates of their normalized bare DOI', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/dup', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        await user.type(screen.getByTestId('identifier-input'), 'https://doi.org/10.1234/dup');
        await user.click(screen.getByTestId('add-button'));

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByText(/this exact relation already exists/i)).toBeInTheDocument();
    });

    it('allows the same identifier when the relation type differs', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/shared', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        await user.type(screen.getByTestId('identifier-input'), '10.1234/shared');
        await user.click(screen.getByTestId('set-references'));
        await user.click(screen.getByTestId('add-button'));

        expect(onChange).toHaveBeenCalledWith([
            { identifier: '10.1234/shared', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            expect.objectContaining({
                identifier: '10.1234/shared',
                identifier_type: 'DOI',
                relation_type: 'References',
                position: 1,
            }),
        ]);
    });

    it('clears duplicate errors after five seconds', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/dup', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        await user.type(screen.getByTestId('identifier-input'), '10.1234/dup');
        await user.click(screen.getByTestId('add-button'));

        act(() => {
            vi.advanceTimersByTime(5000);
        });

        expect(screen.queryByText(/this exact relation already exists/i)).not.toBeInTheDocument();
    });

    it('resets the add-form state after a successful add', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.type(screen.getByTestId('identifier-input'), '10.1234/new');
        await user.click(screen.getByTestId('set-references'));
        await user.click(screen.getByTestId('add-button'));

        expect(screen.getByTestId('identifier-input')).toHaveValue('');
        expect(screen.getByTestId('relation-type')).toHaveTextContent('Cites');
        expect(screen.getByTestId('identifier-type')).toHaveTextContent('DOI');
    });

    it('removes a related work and reindexes positions', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[
                    { identifier: '10.1234/a', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
                    { identifier: '10.1234/b', identifier_type: 'DOI', relation_type: 'References', position: 1 },
                    { identifier: '10.1234/c', identifier_type: 'DOI', relation_type: 'Describes', position: 2 },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('remove-1'));

        expect(onChange).toHaveBeenCalledWith([
            { identifier: '10.1234/a', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            { identifier: '10.1234/c', identifier_type: 'DOI', relation_type: 'Describes', position: 1 },
        ]);
    });

    it('clears stale citation labels and resolved metadata when an item identifier changes', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[
                    {
                        identifier: '10.1234/original',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        citation_label: 'Old citation',
                        related_title: 'Resolved title for old DOI',
                        related_metadata: { publisher: 'GFZ' },
                        source: 'relation_suggestion_assistant',
                        is_repository_curation: true,
                        position: 0,
                    },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('edit-first-item'));

        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier: '10.1234/updated',
                citation_label: null,
                related_title: null,
                related_metadata: null,
                source: 'relation_suggestion_assistant',
                is_repository_curation: true,
                position: 0,
            }),
        ]);
    });

    it('clears stale resolved metadata when only the identifier type changes', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[
                    {
                        identifier: '10.1234/original',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        citation_label: 'Old citation',
                        related_title: 'Resolved title for old DOI',
                        related_metadata: { publisher: 'GFZ' },
                        source: 'relation_suggestion_assistant',
                        is_repository_curation: true,
                        position: 0,
                    },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('edit-first-item-type'));

        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier_type: 'URL',
                citation_label: null,
                related_title: null,
                related_metadata: null,
                source: 'relation_suggestion_assistant',
                is_repository_curation: true,
                position: 0,
            }),
        ]);
    });

    it('keeps untouched sibling items unchanged when editing one related work entry', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[
                    {
                        identifier: '10.1234/original',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        citation_label: 'Old citation',
                        position: 0,
                    },
                    {
                        identifier: '10.1234/untouched',
                        identifier_type: 'DOI',
                        relation_type: 'References',
                        position: 1,
                    },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('edit-first-item'));

        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({ identifier: '10.1234/updated', citation_label: null, position: 0 }),
            { identifier: '10.1234/untouched', identifier_type: 'DOI', relation_type: 'References', position: 1 },
        ]);
    });

    it('rejects edits that would duplicate another related work entry', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[
                    {
                        identifier: '10.1234/original',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        position: 0,
                    },
                    {
                        identifier: '10.1234/duplicate',
                        identifier_type: 'DOI',
                        relation_type: 'References',
                        position: 1,
                    },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('edit-first-item-to-duplicate'));

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByText(/this exact relation already exists/i)).toBeInTheDocument();
    });

    it('preserves citation labels when editing an item without changing its identifier', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[
                    {
                        identifier: '10.1234/original',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        citation_label: 'Existing citation',
                        position: 0,
                    },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('edit-first-item-preserve-label'));

        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier: '10.1234/original',
                citation_label: 'Updated citation without identifier change',
                position: 0,
            }),
        ]);
    });

    it('applies reordered items from the list callback', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[
                    { identifier: '10.1234/a', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
                    { identifier: '10.1234/b', identifier_type: 'DOI', relation_type: 'References', position: 1 },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('reorder-items'));

        expect(onChange).toHaveBeenCalledWith([
            { identifier: '10.1234/b', identifier_type: 'DOI', relation_type: 'References', position: 0 },
            { identifier: '10.1234/a', identifier_type: 'DOI', relation_type: 'Cites', position: 1 },
        ]);
    });

    it('opens and closes the CSV import flow', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        expect(screen.getByTestId('csv-import')).toBeInTheDocument();

        await user.click(screen.getByTestId('csv-import-close'));
        expect(screen.queryByTestId('csv-import')).not.toBeInTheDocument();
        expect(screen.getByTestId('quick-add')).toBeInTheDocument();
    });

    it('skips client-side citation lookups for non-DOI entries', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.type(screen.getByTestId('identifier-input'), 'https://example.org/documentation');
        await user.click(screen.getByTestId('set-url-type'));
        await user.click(screen.getByTestId('add-button'));

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('does not fetch a citation label when the add form already provides one', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.type(screen.getByTestId('identifier-input'), '10.1234/manual-citation');
        await user.click(screen.getByTestId('add-with-manual-citation'));

        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier: '10.1234/manual-citation',
                citation_label: 'Manual citation from add form',
            }),
        ]);
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('appends non-duplicate CSV imports and skips duplicates', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/csv1', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));

        expect(onChange).toHaveBeenCalledWith([
            { identifier: '10.1234/csv1', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            expect.objectContaining({
                identifier: '10.1234/csv2',
                relation_type: 'References',
                position: 1,
            }),
        ]);
        expect(screen.getByText(/skipped 1 duplicate/i)).toBeInTheDocument();
    });

    it('hydrates citation labels only for newly imported DOI rows', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            json: vi.fn().mockResolvedValue({}),
        }) as unknown as typeof fetch;

        render(
            <RelatedWorkField
                relatedWorks={[
                    {
                        identifier: '10.1234/csv1',
                        identifier_type: 'DOI',
                        relation_type: 'Cites',
                        position: 0,
                    },
                ]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('clears CSV duplicate warnings after eight seconds', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(
            <RelatedWorkField
                relatedWorks={[{ identifier: '10.1234/csv1', identifier_type: 'DOI', relation_type: 'Cites', position: 0 }]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));

        expect(screen.getByText(/skipped 1 duplicate/i)).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(8000);
        });

        expect(screen.queryByText(/skipped 1 duplicate/i)).not.toBeInTheDocument();
    });

    it('does not apply a fetched citation label after the related work was removed', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        let resolveCitation: ((value: { citation: string }) => void) | undefined;

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: vi.fn(
                () =>
                    new Promise<{ citation: string }>((resolve) => {
                        resolveCitation = resolve;
                    }),
            ),
        }) as unknown as typeof fetch;

        function StatefulField() {
            const [items, setItems] = useState<RelatedIdentifier[]>([]);

            return <RelatedWorkField relatedWorks={items} onChange={setItems} />;
        }

        render(<StatefulField />);

        await user.type(screen.getByTestId('identifier-input'), '10.1234/pending');
        await user.click(screen.getByTestId('add-button'));
        await user.click(screen.getByTestId('remove-0'));

        await act(async () => {
            resolveCitation?.({ citation: 'Deferred citation' });
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(screen.queryByTestId('related-work-list')).not.toBeInTheDocument();
        expect(screen.queryByText('Deferred citation')).not.toBeInTheDocument();
    });
});
