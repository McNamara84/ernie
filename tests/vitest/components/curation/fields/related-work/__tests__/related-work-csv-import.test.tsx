import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import RelatedWorkCsvImport from '@/components/curation/fields/related-work/related-work-csv-import';

describe('RelatedWorkCsvImport', () => {
    it('renders upload area', () => {
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={vi.fn()} />);
        expect(screen.getByText(/Drop your CSV file here or click to browse/i)).toBeInTheDocument();
    });

    it('renders Cancel button', () => {
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={vi.fn()} />);
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('renders Close CSV import button', () => {
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={vi.fn()} />);
        expect(screen.getByRole('button', { name: /Close CSV import/i })).toBeInTheDocument();
    });

    it('calls onClose when Cancel button is clicked', async () => {
        const onClose = vi.fn();
        const user = userEvent.setup();
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={onClose} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('renders example download link', () => {
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={vi.fn()} />);
        expect(screen.getByText(/Download Example/i)).toBeInTheDocument();
    });

    it('shows CSV format info', () => {
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={vi.fn()} />);
        expect(screen.getByText(/Required columns.*identifier.*relation_type/i)).toBeInTheDocument();
    });

    it('shows import button disabled initially', () => {
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={vi.fn()} />);
        const importBtn = screen.getByRole('button', { name: /^Import$/i });
        expect(importBtn).toBeDisabled();
    });

    it('has a file input element', () => {
        render(<RelatedWorkCsvImport onImport={vi.fn()} onClose={vi.fn()} />);
        const input = document.querySelector('input[type="file"]');
        expect(input).toBeInTheDocument();
    });
});
