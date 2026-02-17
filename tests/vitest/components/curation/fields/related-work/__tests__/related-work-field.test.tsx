import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/identifier-type-detection', () => ({
    detectIdentifierType: vi.fn(() => 'DOI'),
}));

vi.mock('@/components/curation/fields/related-work/related-work-quick-add', () => ({
    default: ({
        onAdd,
    }: {
        onAdd: (data: { identifier: string; identifierType: string; relationType: string }) => void;
    }) => (
        <div data-testid="quick-add">
            <button onClick={() => onAdd({ identifier: '10.5880/test', identifierType: 'DOI', relationType: 'Cites' })}>
                Quick Add
            </button>
        </div>
    ),
}));

vi.mock('@/components/curation/fields/related-work/related-work-advanced-add', () => ({
    default: () => <div data-testid="advanced-add" />,
}));

vi.mock('@/components/curation/fields/related-work/related-work-csv-import', () => ({
    default: () => <div data-testid="csv-import" />,
}));

vi.mock('@/components/curation/fields/related-work/related-work-list', () => ({
    default: ({ items }: { items: Array<{ identifier: string }> }) => (
        <div data-testid="related-work-list">
            {items.map((rw, i) => (
                <div key={i} data-testid="related-work-item">
                    {rw.identifier}
                </div>
            ))}
        </div>
    ),
}));

import RelatedWorkField from '@/components/curation/fields/related-work/related-work-field';

describe('RelatedWorkField', () => {
    it('renders quick add form', () => {
        render(<RelatedWorkField relatedWorks={[]} onChange={vi.fn()} />);
        expect(screen.getByTestId('quick-add')).toBeInTheDocument();
    });

    it('does not render related work list when empty', () => {
        render(<RelatedWorkField relatedWorks={[]} onChange={vi.fn()} />);
        expect(screen.queryByTestId('related-work-list')).not.toBeInTheDocument();
    });

    it('renders Import from CSV button', () => {
        render(<RelatedWorkField relatedWorks={[]} onChange={vi.fn()} />);
        expect(screen.getByRole('button', { name: /Import from CSV/i })).toBeInTheDocument();
    });

    it('renders related work list when items exist', () => {
        const relatedWorks = [
            { identifier: '10.5880/abc', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];
        render(<RelatedWorkField relatedWorks={relatedWorks} onChange={vi.fn()} />);
        expect(screen.getByTestId('related-work-list')).toBeInTheDocument();
    });

    it('calls onChange when quick add submits new work', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<RelatedWorkField relatedWorks={[]} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: 'Quick Add' }));
        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'Cites',
                position: 0,
            }),
        ]);
    });

    it('renders existing related works', () => {
        const relatedWorks = [
            { identifier: '10.5880/abc', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
            { identifier: '10.5880/def', identifier_type: 'DOI', relation_type: 'IsSupplementTo', position: 1 },
        ];
        render(<RelatedWorkField relatedWorks={relatedWorks} onChange={vi.fn()} />);
        expect(screen.getAllByTestId('related-work-item')).toHaveLength(2);
    });

    it('detects duplicates and shows error', async () => {
        const existingWorks = [
            { identifier: '10.5880/test', identifier_type: 'DOI', relation_type: 'Cites', position: 0 },
        ];
        const user = userEvent.setup();
        render(<RelatedWorkField relatedWorks={existingWorks} onChange={vi.fn()} />);

        await user.click(screen.getByRole('button', { name: 'Quick Add' }));
        expect(screen.getByText(/already exists/i)).toBeInTheDocument();
    });
});
