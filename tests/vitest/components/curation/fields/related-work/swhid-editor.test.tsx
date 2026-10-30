import { fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import RelatedWorkAdvancedAdd from '@/components/curation/fields/related-work/related-work-advanced-add';
import RelatedWorkCsvImport from '@/components/curation/fields/related-work/related-work-csv-import';
import RelatedWorkField from '@/components/curation/fields/related-work/related-work-field';
import RelatedWorkItem from '@/components/curation/fields/related-work/related-work-item';
import type { RelatedIdentifier } from '@/types';

const core = 'swh:1:rel:f059f67da934b408306be0175c1f076590b68b7f';
const resolver = `https://archive.softwareheritage.org/${core}`;
const item: RelatedIdentifier = { identifier: '', identifier_type: 'URL', relation_type: 'IsIdenticalTo' };

function Editor({ initial = [item] }: { initial?: RelatedIdentifier[] }) {
    const [items, setItems] = useState(initial);
    return <RelatedWorkField relatedWorks={items} onChange={setItems} />;
}

describe('SWHID editor workflows', () => {
    it('detects a pasted resolver, normalizes on blur and displays a valid archive preview', async () => {
        render(<Editor />);
        const input = screen.getByTestId('related-work-0-identifier-input');
        fireEvent.change(input, { target: { value: `${resolver.toUpperCase()}/;origin=https://example.org/` } });
        expect(screen.getByTestId('identifier-type-badge')).toHaveTextContent('SWHID');
        // A slash before qualifiers is invalid, whereas a slash directly after the final core is normalized.
        fireEvent.change(input, { target: { value: resolver.toUpperCase() + '/' } });
        fireEvent.blur(input);
        await waitFor(() => expect(input).toHaveValue(core));
        expect(screen.getByRole('link')).toHaveAttribute('href', resolver);
        expect(screen.getByRole('link')).toHaveAttribute('target', '_blank');
        await waitFor(() => expect(screen.getByLabelText('Valid')).toBeInTheDocument());
    });

    it('shows malformed candidates as invalid SWHIDs without a preview', async () => {
        render(<Editor />);
        fireEvent.change(screen.getByTestId('related-work-0-identifier-input'), { target: { value: core.slice(0, -1) } });
        expect(screen.getByTestId('identifier-type-badge')).toHaveTextContent('SWHID');
        await waitFor(() => expect(screen.getByLabelText('Invalid')).toBeInTheDocument());
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it.each([true, false])('preserves manual selection: %s', (manual) => {
        const onChange = vi.fn();
        render(
            <RelatedWorkItem
                sortableId="r"
                index={0}
                item={{ ...item, identifier_type_manually_selected: manual }}
                onChange={onChange}
                onRemove={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByTestId('related-work-0-identifier-input'), { target: { value: resolver } });
        expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ identifier_type: manual ? 'URL' : 'SWHID' }));
    });

    it('respects disabled SWHID type and retains manually curated citation labels on normalization', async () => {
        const onChange = vi.fn();
        const { unmount } = render(
            <RelatedWorkItem sortableId="r" index={0} item={item} onChange={onChange} onRemove={vi.fn()} activeIdentifierTypes={['URL']} />,
        );
        fireEvent.change(screen.getByTestId('related-work-0-identifier-input'), { target: { value: core } });
        expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ identifier_type: 'URL' }));
        unmount();
        render(<Editor initial={[{ ...item, identifier: resolver, identifier_type: 'SWHID', citation_label: 'Manual citation' }]} />);
        fireEvent.blur(screen.getByTestId('related-work-0-identifier-input'));
        await waitFor(() => expect(screen.getByTestId('related-work-0-identifier-input')).toHaveValue(core));
        expect(screen.getByDisplayValue('Manual citation')).toBeInTheDocument();
    });

    it('prevents invalid advanced additions even before debounce and normalizes valid additions', () => {
        const onAdd = vi.fn();
        const props = {
            onAdd,
            identifierType: 'SWHID' as const,
            relationType: 'IsIdenticalTo' as const,
            onIdentifierChange: vi.fn(),
            onIdentifierTypeChange: vi.fn(),
            onRelationTypeChange: vi.fn(),
        };
        const { rerender } = render(<RelatedWorkAdvancedAdd {...props} identifier={core.slice(0, -1)} />);
        fireEvent.keyPress(screen.getByLabelText('Identifier'), { key: 'Enter', charCode: 13 });
        expect(onAdd).not.toHaveBeenCalled();
        rerender(<RelatedWorkAdvancedAdd {...props} identifier={resolver + '/'} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add' }));
        expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({ identifier: core, identifierType: 'SWHID' }));
    });

    it('imports quoted qualified CSV values and reports invalid SWHID rows', async () => {
        const onImport = vi.fn();
        render(<RelatedWorkCsvImport onImport={onImport} onClose={vi.fn()} />);
        const file = new File([], 'swhids.csv', { type: 'text/csv' });
        Object.defineProperty(file, 'text', {
            value: async () =>
                `identifier,identifier_type,relation_type\n"${resolver};origin=https://example.org/a,b/",,IsIdenticalTo\n${core.slice(0, -1)},SWHID,References`,
        });
        fireEvent.change(document.getElementById('csv-upload')!, { target: { files: [file] } });
        await waitFor(() => expect(screen.getByText(/Invalid SWHID\. Expected/)).toBeInTheDocument());
        // Existing importer requires correction of errors before submitting a batch.
        expect(onImport).not.toHaveBeenCalled();
        const validFile = new File([], 'valid.csv', { type: 'text/csv' });
        Object.defineProperty(validFile, 'text', {
            value: async () => `identifier,identifier_type,relation_type\n"${resolver};origin=https://example.org/a,b/",,IsIdenticalTo`,
        });
        fireEvent.change(document.getElementById('csv-upload')!, { target: { files: [validFile] } });
        await waitFor(() => expect(screen.getByRole('button', { name: /Import 1/ })).toBeEnabled());
        expect(screen.getByText(/origin=https:\/\/example.org\/a,b\//)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Import 1/ }));
        expect(onImport).toHaveBeenCalledWith([
            { identifier: core + ';origin=https://example.org/a,b/', identifierType: 'SWHID', relationType: 'IsIdenticalTo' },
        ]);
    });

    it('skips equivalent qualified imports but preserves distinct paths and relations', async () => {
        const qualified = core + ';origin=https://example.org/;path=/A.c';
        render(<Editor initial={[{ ...item, identifier: qualified, identifier_type: 'SWHID' }]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Import from CSV' }));
        const file = new File([], 'duplicates.csv', { type: 'text/csv' });
        Object.defineProperty(file, 'text', {
            value: async () =>
                `identifier,identifier_type,relation_type\n${core};path=%2FA.c;origin=https://example.org/,SWHID,IsIdenticalTo\n${core};path=/B.c,SWHID,IsIdenticalTo\n${qualified},SWHID,References`,
        });
        fireEvent.change(document.getElementById('csv-upload')!, { target: { files: [file] } });
        await waitFor(() => expect(screen.getByRole('button', { name: /Import 3/ })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: /Import 3/ }));
        expect(screen.getByText(/Skipped 1 duplicate from CSV import/)).toBeInTheDocument();
        expect(screen.getAllByTestId(/related-work-\d+-identifier-input/)).toHaveLength(3);
        expect(screen.getByTestId('related-work-0-identifier-input')).toHaveValue(qualified);
        expect(screen.getByTestId('related-work-1-identifier-input')).toHaveValue(core + ';path=/B.c');
        expect(screen.getByTestId('related-work-2-identifier-input')).toHaveValue(qualified);
    });
});
