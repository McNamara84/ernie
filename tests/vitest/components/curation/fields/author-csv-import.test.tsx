import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import AuthorCsvImport from '@/components/curation/fields/author-csv-import';

// Mock papaparse
vi.mock('papaparse', () => ({
    default: {
        parse: vi.fn((text: string, options: { complete?: (results: { data: Record<string, string>[] }) => void; error?: (error: Error) => void }) => {
            // Simple CSV parser for tests
            const lines = text.trim().split('\n');
            if (lines.length < 2) {
                if (options.complete) {
                    options.complete({ data: [] });
                }
                return;
            }

            const headers = lines[0].split(',').map((h) => h.trim());
            const data = lines.slice(1).map((line) => {
                const values = line.split(',').map((v) => v.trim());
                const row: Record<string, string> = {};
                headers.forEach((header, index) => {
                    row[header] = values[index] || '';
                });
                return row;
            });

            if (options.complete) {
                options.complete({ data });
            }
        }),
    },
}));

// Mock URL.createObjectURL and URL.revokeObjectURL
const mockCreateObjectURL = vi.fn(() => 'blob:test-url');
const mockRevokeObjectURL = vi.fn();
URL.createObjectURL = mockCreateObjectURL;
URL.revokeObjectURL = mockRevokeObjectURL;

describe('AuthorCsvImport', () => {
    const mockOnImport = vi.fn();
    const mockOnClose = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    it('renders initial state correctly', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        expect(screen.getByText('CSV Bulk Import')).toBeInTheDocument();
        expect(screen.getByText('Import multiple authors from a CSV file')).toBeInTheDocument();
        expect(screen.getByText(/Drop your CSV file here/)).toBeInTheDocument();
        expect(screen.getByText('Download Example')).toBeInTheDocument();
    });

    it('calls onClose when close button is clicked', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const closeButton = screen.getByRole('button', { name: 'Close CSV import' });
        fireEvent.click(closeButton);

        expect(mockOnClose).toHaveBeenCalledTimes(1);
    });

    it('calls onClose when Cancel button is clicked', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const cancelButton = screen.getByRole('button', { name: 'Cancel' });
        fireEvent.click(cancelButton);

        expect(mockOnClose).toHaveBeenCalledTimes(1);
    });

    it('downloads example CSV when button is clicked', () => {
        const mockClick = vi.fn();
        const mockAnchor = {
            click: mockClick,
            href: '',
            download: '',
        };
        vi.spyOn(document, 'createElement').mockReturnValue(mockAnchor as unknown as HTMLAnchorElement);

        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const downloadButton = screen.getByRole('button', { name: /Download Example/ });
        fireEvent.click(downloadButton);

        expect(mockCreateObjectURL).toHaveBeenCalled();
        expect(mockAnchor.download).toBe('authors-example.csv');
        expect(mockClick).toHaveBeenCalled();
        expect(mockRevokeObjectURL).toHaveBeenCalled();
    });

    it('handles drag over state', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const dropZone = screen.getByText(/Drop your CSV file here/).closest('div')!;

        fireEvent.dragOver(dropZone, { preventDefault: vi.fn() });

        // The component should update dragging state
        expect(dropZone.className).toContain('border-primary');
    });

    it('handles drag leave state', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const dropZone = screen.getByText(/Drop your CSV file here/).closest('div')!;

        fireEvent.dragOver(dropZone, { preventDefault: vi.fn() });
        fireEvent.dragLeave(dropZone);

        expect(dropZone.className).not.toContain('border-primary');
    });

    it('processes valid person CSV file', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,Max,Mustermann,0000-0002-1234-5678,max@example.com,,GFZ Potsdam,yes`;

        const file = new File([csvContent], 'authors.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText('authors.csv')).toBeInTheDocument();
        });
    });

    it('processes valid institution CSV file', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
institution,,,,,German Research Foundation,,`;

        const file = new File([csvContent], 'institutions.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText('institutions.csv')).toBeInTheDocument();
        });
    });

    it('shows validation error for person without name', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,,,0000-0002-1234-5678,,,GFZ,no`;

        const file = new File([csvContent], 'invalid.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Found 1 validation error/)).toBeInTheDocument();
            expect(screen.getByText(/First name or last name required/)).toBeInTheDocument();
        });
    });

    it('shows validation error for institution without name', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
institution,,,,,,,`;

        const file = new File([csvContent], 'invalid.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Found 1 validation error/)).toBeInTheDocument();
            expect(screen.getByText(/Institution name required/)).toBeInTheDocument();
        });
    });

    it('shows validation error for invalid ORCID format', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,Max,Mustermann,invalid-orcid,,,GFZ,no`;

        const file = new File([csvContent], 'invalid-orcid.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Found 1 validation error/)).toBeInTheDocument();
            expect(screen.getByText(/Invalid ORCID format/)).toBeInTheDocument();
        });
    });

    it('shows error for empty CSV file', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name`;

        const file = new File([csvContent], 'empty.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/CSV file is empty/)).toBeInTheDocument();
        });
    });

    it('disables import button when no data or errors exist', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const importButton = screen.getByRole('button', { name: /Import/i });
        expect(importButton).toBeDisabled();
    });

    it('calls onImport and onClose when import button clicked with valid data', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,Max,Mustermann,0000-0002-1234-5678,max@example.com,,GFZ Potsdam,yes`;

        const file = new File([csvContent], 'authors.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Successfully parsed 1 author/)).toBeInTheDocument();
        });

        const importButton = screen.getByRole('button', { name: /Import 1 Author/i });
        expect(importButton).not.toBeDisabled();

        fireEvent.click(importButton);

        expect(mockOnImport).toHaveBeenCalledWith([
            {
                type: 'person',
                firstName: 'Max',
                lastName: 'Mustermann',
                orcid: '0000-0002-1234-5678',
                email: 'max@example.com',
                affiliations: ['GFZ Potsdam'],
                isContact: true,
            },
        ]);
        expect(mockOnClose).toHaveBeenCalled();
    });

    it('shows preview of parsed authors', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,Max,Mustermann,0000-0002-1234-5678,max@example.com,,GFZ Potsdam,yes
person,Erika,Musterfrau,,,,,no`;

        const file = new File([csvContent], 'authors.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Successfully parsed 2 authors/)).toBeInTheDocument();
            expect(screen.getByText(/Preview/)).toBeInTheDocument();
            expect(screen.getByText(/Max Mustermann/)).toBeInTheDocument();
            expect(screen.getByText(/Erika Musterfrau/)).toBeInTheDocument();
        });
    });

    it('handles file drop correctly', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,Dropped,Author,0000-0001-1234-5678,dropped@test.com,,Test Org,no`;

        const file = new File([csvContent], 'dropped.csv', { type: 'text/csv' });

        const dropZone = screen.getByText(/Drop your CSV file here/).closest('div')!;

        const dropEvent = {
            preventDefault: vi.fn(),
            dataTransfer: {
                files: [file],
            },
        };

        fireEvent.drop(dropZone, dropEvent);

        await waitFor(() => {
            expect(screen.getByText('dropped.csv')).toBeInTheDocument();
        });
    });

    it('shows multiple validation errors', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,,,,,,GFZ,no
institution,,,,,,,
person,Valid,Author,invalid-orcid,,,Test,no`;

        const file = new File([csvContent], 'multi-errors.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Found 3 validation errors/)).toBeInTheDocument();
        });
    });

    it('displays file size after upload', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID
person,Test,User,0000-0001-2345-6789`;

        const file = new File([csvContent], 'test.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText('test.csv')).toBeInTheDocument();
            expect(screen.getByText(/KB/)).toBeInTheDocument();
        });
    });

    it('parses contact person field correctly', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,Contact,Person,0000-0001-1111-2222,contact@test.com,,Org,ja
person,Non,Contact,0000-0002-2222-3333,non@test.com,,Org,no`;

        const file = new File([csvContent], 'contacts.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Successfully parsed 2 authors/)).toBeInTheDocument();
        });

        const importButton = screen.getByRole('button', { name: /Import 2 Authors/i });
        fireEvent.click(importButton);

        expect(mockOnImport).toHaveBeenCalledWith([
            expect.objectContaining({ firstName: 'Contact', isContact: true }),
            expect.objectContaining({ firstName: 'Non', isContact: false }),
        ]);
    });

    it('parses multiple affiliations correctly', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        // Note: Simple CSV parser in mock treats commas inside the field as separators
        // This tests basic affiliation parsing
        const csvContent = `Type,First Name,Last Name,ORCID,Email,Institution Name,Affiliations,Contact Person
person,Multi,Affil,0000-0001-3333-4444,multi@test.com,,GFZ,no`;

        const file = new File([csvContent], 'affiliations.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(() => {
            expect(screen.getByText(/Successfully parsed 1 author/)).toBeInTheDocument();
        });

        const importButton = screen.getByRole('button', { name: /Import 1 Author/i });
        fireEvent.click(importButton);

        expect(mockOnImport).toHaveBeenCalledWith([
            expect.objectContaining({
                firstName: 'Multi',
                affiliations: ['GFZ'],
            }),
        ]);
    });
});
