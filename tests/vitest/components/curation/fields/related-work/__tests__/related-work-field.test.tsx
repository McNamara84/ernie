import '@testing-library/jest-dom/vitest';

import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkField from '@/components/curation/fields/related-work/related-work-field';
import type { RelatedIdentifier } from '@/types';

// Mock child components to isolate RelatedWorkField logic
vi.mock('@/components/curation/fields/related-work/related-work-quick-add', () => ({
    default: ({
        onAdd,
        identifier,
        onIdentifierChange,
        relationType,
        onRelationTypeChange,
        onToggleAdvanced,
    }: {
        onAdd: (data: { identifier: string; identifierType: string; relationType: string }) => void;
        identifier: string;
        onIdentifierChange: (val: string) => void;
        relationType: string;
        onRelationTypeChange: (val: string) => void;
        onToggleAdvanced: () => void;
    }) => (
        <div data-testid="quick-add">
            <input
                data-testid="identifier-input"
                value={identifier}
                onChange={(e) => onIdentifierChange(e.target.value)}
            />
            <button
                data-testid="add-button"
                onClick={() =>
                    onAdd({ identifier, identifierType: 'DOI', relationType })
                }
            >
                Add
            </button>
            <button data-testid="toggle-advanced" onClick={onToggleAdvanced}>
                Advanced
            </button>
            <span data-testid="relation-type">{relationType}</span>
        </div>
    ),
}));

vi.mock('@/components/curation/fields/related-work/related-work-advanced-add', () => ({
    default: ({
        onAdd,
        identifier,
        onIdentifierChange,
        identifierType,
        onIdentifierTypeChange,
        relationType,
        onRelationTypeChange,
    }: {
        onAdd: (data: { identifier: string; identifierType: string; relationType: string }) => void;
        identifier: string;
        onIdentifierChange: (val: string) => void;
        identifierType: string;
        onIdentifierTypeChange: (val: string) => void;
        relationType: string;
        onRelationTypeChange: (val: string) => void;
    }) => (
        <div data-testid="advanced-add">
            <input
                data-testid="adv-identifier-input"
                value={identifier}
                onChange={(e) => onIdentifierChange(e.target.value)}
            />
            <button
                data-testid="adv-add-button"
                onClick={() =>
                    onAdd({ identifier, identifierType, relationType })
                }
            >
                Add
            </button>
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
    }: {
        items: RelatedIdentifier[];
        onRemove: (index: number) => void;
    }) => (
        <div data-testid="related-work-list">
            {items.map((item, index) => (
                <div key={`${item.identifier}-${item.relation_type}`} data-testid={`item-${index}`}>
                    <span>{item.identifier}</span>
                    <span>{item.relation_type}</span>
                    <button data-testid={`remove-${index}`} onClick={() => onRemove(index)}>
                        Remove
                    </button>
                </div>
            ))}
        </div>
    ),
}));

vi.mock('@/lib/identifier-type-detection', () => ({
    detectIdentifierType: vi.fn(() => 'DOI'),
}));

describe('RelatedWorkField', () => {
    let onChange = vi.fn<(relatedWorks: RelatedIdentifier[]) => void>();

    beforeEach(() => {
        onChange = vi.fn<(relatedWorks: RelatedIdentifier[]) => void>();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders quick-add mode by default', () => {
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        expect(screen.getByTestId('quick-add')).toBeInTheDocument();
        expect(screen.queryByTestId('advanced-add')).not.toBeInTheDocument();
    });

    it('shows the Import from CSV button', () => {
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        expect(screen.getByRole('button', { name: /import from csv/i })).toBeInTheDocument();
    });

    it('does not render the list when there are no related works', () => {
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        expect(screen.queryByTestId('related-work-list')).not.toBeInTheDocument();
    });

    it('renders the list when related works are provided', () => {
        const works: RelatedIdentifier[] = [
            { identifier: '10.1234/test', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];

        render(<RelatedWorkField relatedWorks={works} onChange={onChange} />);

        expect(screen.getByTestId('related-work-list')).toBeInTheDocument();
        expect(screen.getByText('10.1234/test')).toBeInTheDocument();
    });

    it('adds a new related work via quick-add', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        const input = screen.getByTestId('identifier-input');
        await user.type(input, '10.1234/new');
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

    it('prevents adding duplicate identifiers with the same relation type', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const existing: RelatedIdentifier[] = [
            { identifier: '10.1234/dup', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];

        render(<RelatedWorkField relatedWorks={existing} onChange={onChange} />);

        const input = screen.getByTestId('identifier-input');
        await user.type(input, '10.1234/dup');
        await user.click(screen.getByTestId('add-button'));

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByText(/this exact relation already exists/i)).toBeInTheDocument();
    });

    it('detects DOI URL form as duplicate', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const existing: RelatedIdentifier[] = [
            { identifier: 'https://doi.org/10.1234/dup', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];

        // The quick-add mock sends the raw identifier; normalizeIdentifier strips URLs
        render(<RelatedWorkField relatedWorks={existing} onChange={onChange} />);

        const input = screen.getByTestId('identifier-input');
        await user.type(input, '10.1234/dup');
        await user.click(screen.getByTestId('add-button'));

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByText(/this exact relation already exists/i)).toBeInTheDocument();
    });

    it('clears the duplicate error after 5 seconds', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const existing: RelatedIdentifier[] = [
            { identifier: '10.1234/dup', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];

        render(<RelatedWorkField relatedWorks={existing} onChange={onChange} />);

        const input = screen.getByTestId('identifier-input');
        await user.type(input, '10.1234/dup');
        await user.click(screen.getByTestId('add-button'));

        expect(screen.getByText(/this exact relation already exists/i)).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(5000);
        });

        expect(screen.queryByText(/this exact relation already exists/i)).not.toBeInTheDocument();
    });

    it('resets the form state after a successful add', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        const input = screen.getByTestId('identifier-input');
        await user.type(input, '10.1234/new');
        await user.click(screen.getByTestId('add-button'));

        // After adding, identifier should be reset to empty
        expect(input).toHaveValue('');
        // Relation type should reset to Cites
        expect(screen.getByTestId('relation-type')).toHaveTextContent('Cites');
    });

    it('removes a related work and re-indexes positions', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const works: RelatedIdentifier[] = [
            { identifier: '10.1234/a', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            { identifier: '10.1234/b', identifier_type: 'DOI', relation_type: 'References', position: 1 },
            { identifier: '10.1234/c', identifier_type: 'DOI', relation_type: 'Describes', position: 2 },
        ];

        render(<RelatedWorkField relatedWorks={works} onChange={onChange} />);

        await user.click(screen.getByTestId('remove-1'));

        expect(onChange).toHaveBeenCalledWith([
            { identifier: '10.1234/a', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            { identifier: '10.1234/c', identifier_type: 'DOI', relation_type: 'Describes', position: 1 },
        ]);
    });

    it('switches to advanced mode when toggled', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.click(screen.getByTestId('toggle-advanced'));

        expect(screen.getByTestId('advanced-add')).toBeInTheDocument();
        expect(screen.queryByTestId('quick-add')).not.toBeInTheDocument();
    });

    it('switches back to simple mode from advanced', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        // Go to advanced
        await user.click(screen.getByTestId('toggle-advanced'));
        expect(screen.getByTestId('advanced-add')).toBeInTheDocument();

        // Switch back via the "← Switch to simple mode" button
        const switchButton = screen.getByText(/switch to simple mode/i);
        await user.click(switchButton);

        expect(screen.getByTestId('quick-add')).toBeInTheDocument();
        expect(screen.queryByTestId('advanced-add')).not.toBeInTheDocument();
    });

    it('opens CSV import when the Import from CSV button is clicked', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: /import from csv/i }));

        expect(screen.getByTestId('csv-import')).toBeInTheDocument();
        // Quick-add and CSV button should be hidden
        expect(screen.queryByTestId('quick-add')).not.toBeInTheDocument();
    });

    it('closes CSV import and returns to quick-add mode', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-close'));

        expect(screen.queryByTestId('csv-import')).not.toBeInTheDocument();
        expect(screen.getByTestId('quick-add')).toBeInTheDocument();
    });

    it('handles bulk CSV import and appends items', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const existing: RelatedIdentifier[] = [
            { identifier: '10.1234/existing', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];

        render(<RelatedWorkField relatedWorks={existing} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));

        expect(onChange).toHaveBeenCalledWith([
            { identifier: '10.1234/existing', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            expect.objectContaining({
                identifier: '10.1234/csv1',
                identifier_type: 'DOI',
                relation_type: 'Cites',
                position: 1,
            }),
            expect.objectContaining({
                identifier: '10.1234/csv2',
                identifier_type: 'DOI',
                relation_type: 'References',
                position: 2,
            }),
        ]);
    });

    it('skips duplicates during CSV import and shows a warning', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const existing: RelatedIdentifier[] = [
            { identifier: '10.1234/csv1', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];

        render(<RelatedWorkField relatedWorks={existing} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));

        // Should skip csv1 (duplicate) and add csv2
        expect(onChange).toHaveBeenCalledWith([
            { identifier: '10.1234/csv1', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            expect.objectContaining({
                identifier: '10.1234/csv2',
                relation_type: 'References',
                position: 1,
            }),
        ]);

        // Should show duplicate warning
        expect(screen.getByText(/skipped 1 duplicate/i)).toBeInTheDocument();
    });

    it('clears duplicate warning from CSV import after 8 seconds', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const existing: RelatedIdentifier[] = [
            { identifier: '10.1234/csv1', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];

        render(<RelatedWorkField relatedWorks={existing} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: /import from csv/i }));
        await user.click(screen.getByTestId('csv-import-submit'));

        expect(screen.getByText(/skipped 1 duplicate/i)).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(8000);
        });

        expect(screen.queryByText(/skipped 1 duplicate/i)).not.toBeInTheDocument();
    });
});
