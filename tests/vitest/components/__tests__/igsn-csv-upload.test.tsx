import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: vi.fn(() => ({ 'X-CSRF-TOKEN': 'test-token' })),
}));

vi.mock('@/routes/dashboard', () => ({
    uploadIgsnCsv: { url: () => '/api/igsn/upload' },
}));

import { IgsnCsvUpload } from '@/components/igsn-csv-upload';

describe('IgsnCsvUpload', () => {
    it('renders the upload card title', () => {
        render(<IgsnCsvUpload />);
        expect(screen.getByText('IGSN CSV Upload')).toBeInTheDocument();
    });

    it('renders the card description', () => {
        render(<IgsnCsvUpload />);
        expect(screen.getByText(/Upload a pipe-delimited CSV file/i)).toBeInTheDocument();
    });

    it('renders the dropzone area', () => {
        render(<IgsnCsvUpload />);
        expect(screen.getByText(/Drag & drop a CSV file here/i)).toBeInTheDocument();
    });

    it('renders Select CSV File button', () => {
        render(<IgsnCsvUpload />);
        expect(screen.getByRole('button', { name: 'Select CSV File' })).toBeInTheDocument();
    });

    it('has a file input element', () => {
        render(<IgsnCsvUpload />);
        const input = document.querySelector('input[type="file"]');
        expect(input).toBeInTheDocument();
    });

    it('shows initial idle state without success or error', () => {
        render(<IgsnCsvUpload />);
        expect(screen.queryByText(/Upload Successful/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/Upload Failed/i)).not.toBeInTheDocument();
    });
});
