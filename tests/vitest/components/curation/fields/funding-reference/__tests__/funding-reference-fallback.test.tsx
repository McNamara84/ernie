import { act, fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import { type ContextType, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { FundingReferenceField } from '@/components/curation/fields/funding-reference/funding-reference-field';
import { loadRorFunders } from '@/components/curation/fields/funding-reference/ror-search';
import type { FundingReferenceEntry, RorFunder } from '@/components/curation/fields/funding-reference/types';
import { RorCatalogContext } from '@/components/curation/fields/ror-input-feedback';
import { indexRorSuggestions } from '@/lib/ror-input';
import type { AffiliationSuggestion } from '@/types/affiliations';

vi.mock('@/components/curation/fields/funding-reference/ror-search', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/components/curation/fields/funding-reference/ror-search')>()),
    loadRorFunders: vi.fn(),
}));

const rorId = 'https://ror.org/018mejw64';
const fallbackFunders: RorFunder[] = [{ prefLabel: 'Fallback funder', rorId, otherLabel: ['Fallback alias'] }];
const suggestions = [{ value: 'Shared funder', rorId, searchTerms: ['Shared alias'] }];
const healthyCatalog = { suggestions, index: indexRorSuggestions(suggestions), isLoading: false, error: null };
const emptyCatalog = { suggestions: [], index: new Map(), isLoading: false, error: null };
const failedCatalog = { ...healthyCatalog, error: new Error('Shared request failed') };
const initial: FundingReferenceEntry = {
    id: 'funding',
    funderName: '',
    funderIdentifier: '',
    funderIdentifierType: null,
    awardNumber: 'A-1',
    awardUri: 'https://example.org/award',
    awardTitle: 'Existing award',
    isExpanded: false,
};

function Harness({ catalog, onChange }: { catalog: ContextType<typeof RorCatalogContext>; onChange: (value: FundingReferenceEntry[]) => void }) {
    const [value, setValue] = useState([initial]);
    return (
        <RorCatalogContext.Provider value={catalog}>
            <FundingReferenceField
                value={value}
                onChange={(next) => {
                    setValue(next);
                    onChange(next);
                }}
            />
        </RorCatalogContext.Provider>
    );
}

function enter(text: string) {
    const input = screen.getByRole('combobox', { name: /funder name/i });
    fireEvent.change(input, { target: { value: text } });
    return input;
}

describe('Funding reference ROR catalog fallback', () => {
    beforeEach(() => {
        vi.mocked(loadRorFunders).mockReset().mockResolvedValue(fallbackFunders);
    });

    it.each([
        ['failed', failedCatalog],
        ['empty', emptyCatalog],
        ['without usable identifiers', { ...healthyCatalog, index: new Map() }],
        ['absent', null],
    ] as const)('uses fallback name suggestions when the shared catalog is %s', async (_, catalog) => {
        const onChange = vi.fn();
        render(<Harness catalog={catalog} onChange={onChange} />);
        enter('Fallback alias');
        const option = await screen.findByRole('option', { name: /Fallback funder/ });
        expect(loadRorFunders).toHaveBeenCalledOnce();
        expect(onChange).not.toHaveBeenCalled();
        fireEvent.click(option);
        expect(onChange).toHaveBeenLastCalledWith([
            { ...initial, funderName: 'Fallback funder', funderIdentifier: rorId, funderIdentifierType: 'ROR' },
        ]);
    });

    it.each([
        { source: 'shared', query: 'funder', method: 'click' },
        { source: 'shared', query: 'Catalog lookup', method: 'keyboard' },
        { source: 'fallback', query: 'funder', method: 'click' },
        { source: 'fallback', query: 'Catalog lookup', method: 'keyboard' },
    ])('selects only canonical ROR records from $source data when searching for $query', async ({ source, query, method }) => {
        const rawFunders = [
            { prefLabel: 'Valid funder', rorId: '  HTTP://WWW.ROR.ORG/018MEJW64/  ', otherLabel: ['Catalog lookup'] },
            ...[undefined, null, '', '   ', 'invalid', 'https://ror.org/bad', 'https://example.org/018mejw64', `${rorId}?query=yes`].map(
                (rorId, index) => ({ prefLabel: `Invalid funder ${index}`, rorId, otherLabel: ['Catalog lookup'] }),
            ),
        ];
        // Simulate malformed cached records that bypass the API response's static types.
        vi.mocked(loadRorFunders).mockResolvedValue(rawFunders as RorFunder[]);
        const suggestions = rawFunders.map((item) => ({
            value: item.prefLabel,
            rorId: item.rorId,
            searchTerms: item.otherLabel,
        })) as AffiliationSuggestion[];
        const catalog = source === 'shared' ? { ...healthyCatalog, suggestions, index: indexRorSuggestions(suggestions) } : failedCatalog;
        const onChange = vi.fn();
        render(<Harness catalog={catalog} onChange={onChange} />);
        const input = enter(query);
        const option = await screen.findByRole('option', { name: /Valid funder/ });
        expect(screen.getAllByRole('option')).toHaveLength(1);
        expect(option).toHaveTextContent(rorId);
        expect(onChange).not.toHaveBeenCalled();
        if (method === 'keyboard') fireEvent.keyDown(input, { key: 'Enter' });
        else fireEvent.click(option);
        expect(onChange).toHaveBeenCalledExactlyOnceWith([
            { ...initial, funderName: 'Valid funder', funderIdentifier: rorId, funderIdentifierType: 'ROR' },
        ]);
        expect(loadRorFunders).toHaveBeenCalledTimes(source === 'shared' ? 0 : 1);
    });

    it.each([
        { text: '018mejw64', name: 'Fallback funder', method: 'keyboard' },
        { text: rorId, name: 'Fallback funder', method: 'click' },
        { text: `Custom division (${rorId})`, name: 'Custom division', method: 'click' },
    ])('confirms $text from fallback data after a shared error using $method', async ({ text, name, method }) => {
        const onChange = vi.fn();
        const pending = Promise.withResolvers<RorFunder[]>();
        vi.mocked(loadRorFunders).mockReturnValue(pending.promise);
        render(<Harness catalog={failedCatalog} onChange={onChange} />);
        const input = enter(text);
        expect(screen.getByText(/Loading ROR data/, { selector: '[role="status"]' })).toBeInTheDocument();
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(onChange).not.toHaveBeenCalled();
        await act(async () => pending.resolve(fallbackFunders));
        const option = screen.getByRole('option');
        expect(option).toHaveTextContent('ROR record: Fallback funder');
        expect(option).toHaveTextContent(rorId);
        expect(onChange).not.toHaveBeenCalled();
        if (method === 'keyboard') fireEvent.keyDown(input, { key: 'Enter' });
        else fireEvent.click(option);
        expect(onChange).toHaveBeenCalledExactlyOnceWith([{ ...initial, funderName: name, funderIdentifier: rorId, funderIdentifierType: 'ROR' }]);
    });

    it('uses a healthy shared catalog without making a fallback request', () => {
        const onChange = vi.fn();
        render(<Harness catalog={healthyCatalog} onChange={onChange} />);
        enter('018mejw64');
        fireEvent.click(screen.getByRole('option', { name: /Shared funder/ }));
        expect(onChange).toHaveBeenCalledExactlyOnceWith([
            { ...initial, funderName: 'Shared funder', funderIdentifier: rorId, funderIdentifierType: 'ROR' },
        ]);
        expect(loadRorFunders).not.toHaveBeenCalled();
    });

    it.each([
        ['failure', failedCatalog],
        ['empty response', emptyCatalog],
    ] as const)('waits for the shared request before falling back after %s', async (_, catalog) => {
        const onChange = vi.fn();
        const view = render(<Harness catalog={{ ...emptyCatalog, isLoading: true }} onChange={onChange} />);
        enter('018mejw64');
        expect(loadRorFunders).not.toHaveBeenCalled();
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        view.rerender(<Harness catalog={catalog} onChange={onChange} />);
        expect(await screen.findByRole('option')).toHaveTextContent('Fallback funder');
        expect(loadRorFunders).toHaveBeenCalledOnce();
        expect(onChange).not.toHaveBeenCalled();
    });

    it('does not repeat an empty fallback on parent renders and allows an explicit retry', async () => {
        vi.mocked(loadRorFunders).mockResolvedValueOnce([]);
        const onChange = vi.fn();
        const view = render(<Harness catalog={failedCatalog} onChange={onChange} />);
        const input = enter('018mejw64');
        const retry = await screen.findByRole('button', { name: 'Reload ROR directory' });
        expect(screen.getByText('The local ROR directory is unavailable.')).toBeInTheDocument();
        view.rerender(<Harness catalog={{ ...failedCatalog }} onChange={onChange} />);
        expect(loadRorFunders).toHaveBeenCalledOnce();
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(onChange).not.toHaveBeenCalled();
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        fireEvent.click(retry);
        expect(await screen.findByRole('option')).toHaveTextContent('Fallback funder');
        expect(loadRorFunders).toHaveBeenCalledTimes(2);
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(onChange).toHaveBeenCalledExactlyOnceWith([
            { ...initial, funderName: 'Fallback funder', funderIdentifier: rorId, funderIdentifierType: 'ROR' },
        ]);
    });

    it('fills a missing name from fallback data for an existing ROR identifier', async () => {
        vi.mocked(loadRorFunders).mockResolvedValue([{ ...fallbackFunders[0], rorId: 'HTTP://WWW.ROR.ORG/018MEJW64/' }]);
        const onChange = vi.fn();
        const existing = { ...initial, funderIdentifier: rorId, funderIdentifierType: 'ROR' };
        render(
            <RorCatalogContext.Provider value={failedCatalog}>
                <FundingReferenceField value={[existing]} onChange={onChange} />
            </RorCatalogContext.Provider>,
        );
        await waitFor(() => expect(onChange).toHaveBeenCalledExactlyOnceWith([{ ...existing, funderName: 'Fallback funder' }]));
    });

    it('keeps an existing name and identifier when the fallback is unavailable', async () => {
        vi.mocked(loadRorFunders).mockResolvedValue([]);
        const onChange = vi.fn();
        const existing = { ...initial, funderName: 'Existing division', funderIdentifier: rorId, funderIdentifierType: 'ROR' };
        render(
            <RorCatalogContext.Provider value={failedCatalog}>
                <FundingReferenceField value={[existing]} onChange={onChange} />
            </RorCatalogContext.Provider>,
        );
        await waitFor(() => expect(screen.queryByText('Loading ROR data...')).not.toBeInTheDocument());
        expect(screen.getByRole('combobox')).toHaveValue('Existing division');
        expect(screen.getByRole('link', { name: /ROR:/ })).toHaveAttribute('href', rorId);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('prefers a recovered shared catalog and ignores a late fallback response', async () => {
        const pending = Promise.withResolvers<RorFunder[]>();
        vi.mocked(loadRorFunders).mockReturnValue(pending.promise);
        const onChange = vi.fn();
        const view = render(<Harness catalog={failedCatalog} onChange={onChange} />);
        enter('018mejw64');
        view.rerender(<Harness catalog={healthyCatalog} onChange={onChange} />);
        expect(screen.getByRole('option')).toHaveTextContent('ROR record: Shared funder');
        await act(async () => pending.resolve(fallbackFunders));
        expect(screen.getByRole('option')).toHaveTextContent('ROR record: Shared funder');
        fireEvent.click(screen.getByRole('option'));
        expect(onChange).toHaveBeenCalledExactlyOnceWith([
            { ...initial, funderName: 'Shared funder', funderIdentifier: rorId, funderIdentifierType: 'ROR' },
        ]);
        expect(loadRorFunders).toHaveBeenCalledOnce();
    });
});
