import { act, fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkField from '@/components/curation/fields/related-work/related-work-field';
import { useRelatedWorkEditor } from '@/hooks/use-related-work-editor';
import type { RelatedIdentifier } from '@/types';

function work(identifier: string, citation_label: string | null): RelatedIdentifier {
    return { identifier, identifier_type: 'ARK', relation_type: 'Cites', citation_label };
}

function Editor({ initial, incoming = [], saved = true }: { initial: RelatedIdentifier[]; incoming?: RelatedIdentifier[]; saved?: boolean }) {
    const editor = useRelatedWorkEditor(initial, saved);
    const [open, setOpen] = useState(true);
    return (
        <>
            <button onClick={() => setOpen((value) => !value)}>Toggle section</button>
            <button onClick={() => editor.mergeImported(incoming)}>Import metadata</button>
            <output data-testid="stored-data">{JSON.stringify(editor.relatedWorks)}</output>
            {open && <RelatedWorkField entries={editor.entries} displayEntries={editor.displayEntries} onChange={editor.setEntries} />}
        </>
    );
}

function stored(): RelatedIdentifier[] {
    return JSON.parse(screen.getByTestId('stored-data').textContent!);
}

function identifiers(): string[] {
    return screen.getAllByTestId(/related-work-\d+-identifier-input/).map((input) => (input as HTMLInputElement).value);
}

describe('Related Work display and edit integration', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('edits and deletes the correct canonical rows and preserves surviving input identity', () => {
        const initial = [
            { ...work('ark:/z', 'Zulu'), id: 30, source: 'relation_suggestion_assistant' as const },
            { ...work('ark:/a', 'Alpha'), id: 10 },
            { ...work('ark:/b', 'Beta'), id: 20 },
        ];
        render(<Editor initial={initial} />);
        expect(identifiers()).toEqual(['ark:/a', 'ark:/b', 'ark:/z']);
        expect(screen.queryByRole('button', { name: /Reorder related work/i })).not.toBeInTheDocument();

        const rows = screen.getAllByRole('listitem');
        fireEvent.change(within(rows[0]).getByRole('textbox', { name: 'Citation label' }), { target: { value: 'ZZZ edited' } });
        expect(identifiers()).toEqual(['ark:/a', 'ark:/b', 'ark:/z']);
        expect(stored().map(({ id, citation_label }) => ({ id, citation_label }))).toEqual([
            { id: 30, citation_label: 'Zulu' },
            { id: 10, citation_label: 'ZZZ edited' },
            { id: 20, citation_label: 'Beta' },
        ]);

        const survivingInput = within(rows[2]).getByRole('textbox', { name: 'Citation label' });
        survivingInput.focus();
        fireEvent.click(within(rows[1]).getByRole('button', { name: /Remove related work/ }));
        expect(identifiers()).toEqual(['ark:/a', 'ark:/z']);
        expect(within(screen.getAllByRole('listitem')[1]).getByRole('textbox', { name: 'Citation label' })).toBe(survivingInput);
        expect(survivingInput).toHaveFocus();
        expect(stored().map(({ id }) => id)).toEqual([30, 10]);
        expect(stored()[0].source).toBe('relation_suggestion_assistant');
    });

    it('appends new empty cards and preview imports without sorting again after collapse', () => {
        render(
            <Editor
                initial={[work('ark:/z', 'Zulu'), work('ark:/a', 'Alpha')]}
                incoming={[work('ark:/new-z', 'Zulu'), work('ark:/new-a', 'Alpha')]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add Related Work' }));
        expect(identifiers()).toEqual(['ark:/a', 'ark:/z', '']);
        fireEvent.change(screen.getByTestId('related-work-2-identifier-input'), { target: { value: 'ark:/manual' } });
        fireEvent.change(screen.getAllByRole('textbox', { name: 'Citation label' })[2], { target: { value: 'AAA' } });
        fireEvent.click(screen.getByRole('button', { name: 'Import metadata' }));
        const expected = ['ark:/a', 'ark:/z', 'ark:/manual', 'ark:/new-z', 'ark:/new-a'];
        expect(identifiers()).toEqual(expected);
        fireEvent.click(screen.getByRole('button', { name: 'Toggle section' }));
        expect(screen.queryByRole('list', { name: 'Related works' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Toggle section' }));
        expect(identifiers()).toEqual(expected);
        expect(stored().map(({ identifier }) => identifier)).toEqual(['ark:/z', 'ark:/a', 'ark:/manual', 'ark:/new-z', 'ark:/new-a']);
    });

    it('appends real CSV rows in file order and preserves that view after reopening the section', async () => {
        render(<Editor initial={[work('ark:/z', 'Zulu'), work('ark:/a', 'Alpha')]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Import from CSV' }));
        const file = new File([], 'works.csv', { type: 'text/csv' });
        Object.defineProperty(file, 'text', {
            value: async () => 'identifier,identifier_type,relation_type\nark:/new-z,ARK,References\nark:/new-a,ARK,Cites',
        });
        fireEvent.change(document.getElementById('csv-upload')!, { target: { files: [file] } });
        await waitFor(() => expect(screen.getByRole('button', { name: /Import 2/ })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: /Import 2/ }));
        expect(identifiers()).toEqual(['ark:/a', 'ark:/z', 'ark:/new-z', 'ark:/new-a']);
        expect(stored().map(({ identifier }) => identifier)).toEqual(['ark:/z', 'ark:/a', 'ark:/new-z', 'ark:/new-a']);
        fireEvent.click(screen.getByRole('button', { name: 'Toggle section' }));
        fireEvent.click(screen.getByRole('button', { name: 'Toggle section' }));
        expect(identifiers()).toEqual(['ark:/a', 'ark:/z', 'ark:/new-z', 'ark:/new-a']);
    });

    it('applies a pending label to current parent state after unmount without reverting imports or other edits', async () => {
        let resolve!: (response: Response) => void;
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise<Response>((done) => {
                        resolve = done;
                    }),
            ),
        );
        render(
            <Editor
                initial={[work('ark:/b', 'Beta'), { ...work('10.5880/pending', null), identifier_type: 'DOI' }]}
                incoming={[work('ark:/new', 'AAA')]}
            />,
        );
        fireEvent.blur(screen.getByTestId('related-work-0-identifier-input'));
        expect(within(screen.getAllByRole('listitem')[0]).getByText(/Resolving citation label/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Toggle section' }));
        fireEvent.click(screen.getByRole('button', { name: 'Import metadata' }));
        fireEvent.click(screen.getByRole('button', { name: 'Toggle section' }));
        fireEvent.change(screen.getAllByRole('textbox', { name: 'Citation label' })[1], { target: { value: 'Zulu edited while waiting' } });
        await act(async () => resolve(new Response(JSON.stringify({ citation: 'Alpha resolved' }))));
        expect(stored().map(({ citation_label }) => citation_label)).toEqual(['Zulu edited while waiting', 'Alpha resolved', 'AAA']);
        expect(identifiers()).toEqual(['10.5880/pending', 'ark:/b', 'ark:/new']);
    });

    it.each(['remove', 'replace identifier', 'edit label'])('does not overwrite a newer %s with a pending lookup', async (action) => {
        let resolve!: (response: Response) => void;
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise<Response>((done) => {
                        resolve = done;
                    }),
            ),
        );
        render(<Editor initial={[work('ark:/b', 'Beta'), { ...work('10.5880/pending', null), identifier_type: 'DOI' }]} />);
        fireEvent.blur(screen.getByTestId('related-work-0-identifier-input'));
        const first = within(screen.getAllByRole('listitem')[0]);
        if (action === 'remove') fireEvent.click(first.getByRole('button', { name: /Remove related work/ }));
        if (action === 'replace identifier')
            fireEvent.change(screen.getByTestId('related-work-0-identifier-input'), { target: { value: 'ark:/replacement' } });
        if (action === 'edit label') fireEvent.change(first.getByRole('textbox', { name: 'Citation label' }), { target: { value: 'Manual label' } });
        await act(async () => resolve(new Response(JSON.stringify({ citation: 'Stale resolved label' }))));
        expect(stored().some(({ citation_label }) => citation_label === 'Stale resolved label')).toBe(false);
        expect(stored()[0]).toMatchObject(work('ark:/b', 'Beta'));
        if (action === 'remove') expect(stored()).toHaveLength(1);
        if (action === 'replace identifier') expect(stored()[1].identifier).toBe('ark:/replacement');
        if (action === 'edit label') expect(stored()[1].citation_label).toBe('Manual label');
    });
});
