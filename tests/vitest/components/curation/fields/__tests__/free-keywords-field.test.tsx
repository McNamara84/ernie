import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

// Mock TagInputField since it relies on Tagify which needs real DOM
vi.mock('@/components/curation/fields/tag-input-field', () => ({
    default: ({ id, label, hideLabel, value, placeholder, 'data-testid': testId }: {
        id: string;
        label: string;
        hideLabel?: boolean;
        value: Array<{ value: string }>;
        placeholder?: string;
        'data-testid'?: string;
    }) => (
        <div data-testid={testId || id}>
            {!hideLabel && <label>{label}</label>}
            <input placeholder={placeholder} defaultValue={value.map((v) => v.value).join(', ')} />
        </div>
    ),
    TagInputField: ({ id, label, hideLabel, value, placeholder, 'data-testid': testId }: {
        id: string;
        label: string;
        hideLabel?: boolean;
        value: Array<{ value: string }>;
        placeholder?: string;
        'data-testid'?: string;
    }) => (
        <div data-testid={testId || id}>
            {!hideLabel && <label>{label}</label>}
            <input placeholder={placeholder} defaultValue={value.map((v) => v.value).join(', ')} />
        </div>
    ),
}));

vi.mock('@/components/curation/fields/free-keywords-csv-import', () => ({
    default: () => <div data-testid="csv-import" />,
}));

import FreeKeywordsField from '@/components/curation/fields/free-keywords-field';

describe('FreeKeywordsField', () => {
    it('renders the Free Keywords label', () => {
        render(<FreeKeywordsField keywords={[]} onChange={vi.fn()} />);
        expect(screen.getByText('Free Keywords')).toBeInTheDocument();
    });

    it('renders CSV Import button', () => {
        render(<FreeKeywordsField keywords={[]} onChange={vi.fn()} />);
        expect(screen.getByRole('button', { name: /CSV Import/i })).toBeInTheDocument();
    });

    it('renders info description text', () => {
        render(<FreeKeywordsField keywords={[]} onChange={vi.fn()} />);
        expect(screen.getByText(/Add custom keywords to describe your dataset/)).toBeInTheDocument();
    });

    it('shows keyword count when keywords exist', () => {
        const keywords = [{ value: 'climate' }, { value: 'temperature' }];
        render(<FreeKeywordsField keywords={keywords} onChange={vi.fn()} />);
        expect(screen.getByText('2 keywords added')).toBeInTheDocument();
    });

    it('shows singular keyword count for single keyword', () => {
        const keywords = [{ value: 'geology' }];
        render(<FreeKeywordsField keywords={keywords} onChange={vi.fn()} />);
        expect(screen.getByText('1 keyword added')).toBeInTheDocument();
    });

    it('does not show keyword count when empty', () => {
        render(<FreeKeywordsField keywords={[]} onChange={vi.fn()} />);
        expect(screen.queryByText(/keyword.*added/)).not.toBeInTheDocument();
    });

    it('opens CSV import dialog on button click', async () => {
        const user = userEvent.setup();
        render(<FreeKeywordsField keywords={[]} onChange={vi.fn()} />);

        await user.click(screen.getByRole('button', { name: /CSV Import/i }));
        expect(screen.getByText('Import Free Keywords from CSV')).toBeInTheDocument();
    });

    it('renders helper text', () => {
        render(<FreeKeywordsField keywords={[]} onChange={vi.fn()} />);
        expect(screen.getByText(/Press Enter or type a comma/)).toBeInTheDocument();
    });
});
