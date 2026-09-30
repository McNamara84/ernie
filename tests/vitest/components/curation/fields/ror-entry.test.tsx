import { createTagifyMock } from '@test-helpers/tagify-mock';
import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { FundingReferenceItem } from '@/components/curation/fields/funding-reference/funding-reference-item';
import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference/types';
import { RorCatalogContext, RorInputFeedback } from '@/components/curation/fields/ror-input-feedback';
import { TagInputField } from '@/components/curation/fields/tag-input-field';
import { RorInputDraftContext, useRorInputDrafts } from '@/hooks/use-ror-input-drafts';
import { indexRorSuggestions } from '@/lib/ror-input';
import type { AffiliationTag } from '@/types/affiliations';

vi.mock('@yaireo/tagify', () => createTagifyMock());
const rorId = 'https://ror.org/04z8jg394';
const suggestions = [{ value: 'Directory name', rorId, searchTerms: [] }];

function AffiliationHarness({ loading = false, failed = false, initial = [] }: { loading?: boolean; failed?: boolean; initial?: AffiliationTag[] }) {
    const [tags, setTags] = useState<AffiliationTag[]>(initial);
    const [visible, setVisible] = useState(true);
    const drafts = useRorInputDrafts();
    return (
        <RorInputDraftContext.Provider value={drafts}>
            <RorCatalogContext.Provider
                value={{ index: indexRorSuggestions(suggestions), suggestions, isLoading: loading, error: failed ? new Error('offline') : null }}
            >
                <button onClick={() => setVisible(!visible)}>Toggle</button>
                {visible && (
                    <TagInputField
                        id="affiliation"
                        label="Affiliations"
                        value={tags}
                        onChange={({ tags: next }) => setTags(next)}
                        ror={{ suggestions, section: 'authors' }}
                    />
                )}
                <div data-testid="tags">{JSON.stringify(tags)}</div>
                <div data-testid="drafts">{JSON.stringify(drafts.drafts)}</div>
            </RorCatalogContext.Provider>
        </RorInputDraftContext.Provider>
    );
}

function typeAffiliation(text: string) {
    const input = document.querySelector('.tagify__input') as HTMLElement;
    input.textContent = text;
    fireEvent.input(input);
    return input;
}

describe('Affiliation ROR input', () => {
    it('offers a local directory reload for an empty catalogue without assigning an identifier', () => {
        const retry = vi.fn();
        const onSelect = vi.fn();
        render(
            <RorInputFeedback id="empty" text={`Custom (${rorId})`} index={new Map()} open retry={retry} onSelect={onSelect} onDiscard={vi.fn()} />,
        );
        expect(screen.getByRole('status')).toHaveTextContent('unavailable');
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        fireEvent.click(screen.getByText('Reload ROR directory'));
        expect(retry).toHaveBeenCalledOnce();
        expect(onSelect).not.toHaveBeenCalled();
    });
    it('explains malformed input and preserves its custom label as plain text', () => {
        render(<AffiliationHarness />);
        typeAffiliation('<img src=x onerror=alert(1)> (https://ror.org/bad)');
        expect(screen.getByRole('status')).toHaveTextContent('Enter a valid ROR ID or ROR URL.');
        fireEvent.click(screen.getByText('Use name without ROR ID'));
        expect(JSON.parse(screen.getByTestId('tags').textContent!)).toEqual([{ value: '<img src=x onerror=alert(1)>', rorId: null }]);
        expect(document.querySelector('img')).toBeNull();
    });
    it.each(['04z8jg394', rorId, `Custom, Name (${rorId})`])('only commits %s after explicit selection', (text) => {
        render(<AffiliationHarness />);
        const input = typeAffiliation(text);
        fireEvent.blur(input);
        expect(screen.getByTestId('tags')).toHaveTextContent('[]');
        expect(screen.getByRole('option')).toHaveTextContent('Directory name');
        fireEvent.click(screen.getByRole('option'));
        expect(JSON.parse(screen.getByTestId('tags').textContent!)).toEqual([
            { value: text.startsWith('Custom') ? 'Custom, Name' : 'Directory name', rorId },
        ]);
        expect(screen.getByTestId('drafts')).toHaveTextContent('{}');
    });
    it('supports Escape, arrows and Enter without submitting a surrounding form', () => {
        render(<AffiliationHarness />);
        const input = typeAffiliation('04z8jg394');
        fireEvent.keyDown(input, { key: 'Escape' });
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(screen.getByTestId('tags')).toHaveTextContent('[]');
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(screen.getByTestId('tags')).toHaveTextContent(rorId);
    });
    it('retains an unknown input across unmounts and explicitly converts only its name', () => {
        render(<AffiliationHarness />);
        typeAffiliation('Custom (012345678)');
        fireEvent.click(screen.getByText('Toggle'));
        fireEvent.click(screen.getByText('Toggle'));
        expect(document.querySelector('.tagify__input')).toHaveTextContent('Custom (012345678)');
        fireEvent.click(screen.getByText('Use name without ROR ID'));
        expect(JSON.parse(screen.getByTestId('tags').textContent!)).toEqual([{ value: 'Custom', rorId: null }]);
    });
    it('does not turn a bare unknown ID into a name and allows discarding it', () => {
        render(<AffiliationHarness />);
        typeAffiliation('012345678');
        expect(screen.queryByText('Use name without ROR ID')).not.toBeInTheDocument();
        fireEvent.click(screen.getByText('Discard ROR input'));
        expect(screen.getByTestId('drafts')).toHaveTextContent('{}');
        expect(screen.getByTestId('tags')).toHaveTextContent('[]');
    });
    it('distinguishes loading and failed lookup, and resolves after the data becomes available', () => {
        const view = render(<AffiliationHarness loading />);
        typeAffiliation('04z8jg394');
        expect(screen.getByRole('status')).toHaveTextContent('Loading');
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        view.rerender(<AffiliationHarness failed />);
        expect(screen.getByRole('status')).toHaveTextContent('unavailable');
        view.rerender(<AffiliationHarness />);
        expect(screen.getByRole('option')).toHaveTextContent(rorId);
    });
    it('allows an explicit name without ID when a cached match cannot be verified after a loading error', () => {
        render(<AffiliationHarness failed />);
        typeAffiliation(`Custom (${rorId})`);
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        fireEvent.click(screen.getByText('Use name without ROR ID'));
        expect(JSON.parse(screen.getByTestId('tags').textContent!)).toEqual([{ value: 'Custom', rorId: null }]);
    });
    it('does not confirm a ROR entry while an IME composition is active', () => {
        render(<AffiliationHarness />);
        const input = typeAffiliation('04z8jg394');
        fireEvent.keyDown(input, { key: 'Enter', isComposing: true });
        expect(screen.getByTestId('tags')).toHaveTextContent('[]');
        expect(screen.getByRole('option')).toBeInTheDocument();
    });
    it('deduplicates confirmed name-ID pairs but preserves the same name with a different identifier', () => {
        render(<AffiliationHarness initial={[{ value: 'Directory name', rorId: 'https://ror.org/018mejw64' }]} />);
        typeAffiliation('04z8jg394');
        fireEvent.click(screen.getByRole('option'));
        typeAffiliation('04z8jg394');
        fireEvent.click(screen.getByRole('option'));
        expect(JSON.parse(screen.getByTestId('tags').textContent!)).toEqual([
            { value: 'Directory name', rorId: 'https://ror.org/018mejw64' },
            { value: 'Directory name', rorId },
        ]);
    });
    it('retains comma-separated ordinary name entry', () => {
        render(<AffiliationHarness />);
        const input = typeAffiliation('Institute A, Institute B, Institute A');
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(JSON.parse(screen.getByTestId('tags').textContent!)).toEqual([
            { value: 'Institute A', rorId: null },
            { value: 'Institute B', rorId: null },
        ]);
    });
});

describe('Funding ROR input', () => {
    const initial: FundingReferenceEntry = {
        id: 'fund',
        funderName: 'Existing name',
        funderIdentifier: 'https://doi.org/10.13039/1000',
        funderIdentifierType: 'Crossref Funder ID',
        awardNumber: 'A-1',
        awardUri: '',
        awardTitle: 'Award',
        isExpanded: false,
    };
    function renderFunding() {
        const onFieldsChange = vi.fn();
        const onNameChange = vi.fn();
        render(
            <FundingReferenceItem
                funding={initial}
                index={0}
                canRemove
                rorFunders={[{ prefLabel: 'Directory name', rorId, otherLabel: [] }]}
                onFieldsChange={onFieldsChange}
                onFunderNameChange={onNameChange}
                onAwardNumberChange={vi.fn()}
                onAwardUriChange={vi.fn()}
                onAwardTitleChange={vi.fn()}
                onToggleExpanded={vi.fn()}
                onRemove={vi.fn()}
            />,
        );
        return { onFieldsChange, onNameChange, input: screen.getByRole('combobox') };
    }
    it.each(['04z8jg394', rorId, `Custom Funder (${rorId})`])('atomically replaces the identifier for %s after confirming', (text) => {
        const { input, onFieldsChange, onNameChange } = renderFunding();
        fireEvent.change(input, { target: { value: text } });
        fireEvent.blur(input);
        expect(onFieldsChange).not.toHaveBeenCalled();
        expect(onNameChange).not.toHaveBeenCalled();
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(onFieldsChange).toHaveBeenCalledExactlyOnceWith({
            funderName: text.startsWith('Custom') ? 'Custom Funder' : 'Directory name',
            funderIdentifier: rorId,
            funderIdentifierType: 'ROR',
        });
    });
    it('restores the previously confirmed funding when an unresolved edit is discarded', () => {
        const { input, onFieldsChange } = renderFunding();
        fireEvent.change(input, { target: { value: 'Unknown (012345678)' } });
        fireEvent.click(screen.getByText('Discard ROR input'));
        expect(input).toHaveValue('Existing name');
        expect(onFieldsChange).not.toHaveBeenCalled();
    });
    it('clears the old identifier and type only after explicitly selecting the unlinked name', () => {
        const { input, onFieldsChange } = renderFunding();
        fireEvent.change(input, { target: { value: 'Unknown (012345678)' } });
        fireEvent.click(screen.getByText('Use name without ROR ID'));
        expect(onFieldsChange).toHaveBeenCalledExactlyOnceWith({ funderName: 'Unknown', funderIdentifier: '', funderIdentifierType: null });
    });
    it('does not confirm funding while an IME composition is active', () => {
        const { input, onFieldsChange } = renderFunding();
        fireEvent.change(input, { target: { value: '04z8jg394' } });
        fireEvent.keyDown(input, { key: 'Enter', isComposing: true });
        expect(onFieldsChange).not.toHaveBeenCalled();
    });
});
