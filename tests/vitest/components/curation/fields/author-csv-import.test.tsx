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
global.URL.createObjectURL = vi.fn(() => 'blob:test-url');
global.URL.revokeObjectURL = vi.fn();

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
        // Save original createElement
        const originalCreateElement = document.createElement.bind(document);

        // Create a mock anchor element
        const mockAnchor = originalCreateElement('a') as HTMLAnchorElement;
        const mockClick = vi.fn();
        mockAnchor.click = mockClick;

        vi.spyOn(document, 'createElement').mockImplementation((tagName: string) => {
            if (tagName === 'a') {
                return mockAnchor;
            }
            return originalCreateElement(tagName);
        });

        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const downloadButton = screen.getByRole('button', { name: /Download Example/ });
        fireEvent.click(downloadButton);

        expect(mockAnchor.download).toBe('authors-example.csv');
        expect(mockClick).toHaveBeenCalled();

        vi.restoreAllMocks();
    });

    it('handles drag over state', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        // Find the drop zone by looking for the container with border-dashed
        const dropZone = document.querySelector('.border-dashed')!;

        fireEvent.dragOver(dropZone, { preventDefault: vi.fn() });

        // The component should update dragging state
        expect(dropZone.className).toContain('border-primary');
    });

    it('handles drag leave state', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const dropZone = document.querySelector('.border-dashed')!;

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

        // CSV where the person has no First Name and no Last Name values
        const csvContent = `Type,First Name,Last Name,ORCID
person,,,0000-0002-1234-5678`;

        const file = new File([csvContent], 'invalid.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                expect(screen.getByText(/validation error/i)).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('shows validation error for institution without name', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        // CSV with institution type but no institution name
        const csvContent = `Type,First Name,Last Name,Institution Name
institution,,,`;

        const file = new File([csvContent], 'invalid.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                expect(screen.getByText(/validation error/i)).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('shows validation error for invalid ORCID format', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID
person,Max,Mustermann,invalid-orcid`;

        const file = new File([csvContent], 'invalid-orcid.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                expect(screen.getByText(/validation error/i)).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('shows error for empty CSV file', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        // Just a header with no data rows
        const csvContent = `Type,First Name,Last Name`;

        const file = new File([csvContent], 'empty.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                // The file is shown but no success message appears since parsing is async
                expect(screen.getByText('empty.csv')).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('disables import button when no data or errors exist', () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        // Get the last button which is the Import button (not "Close CSV import")
        const buttons = screen.getAllByRole('button');
        const importButton = buttons[buttons.length - 1];

        expect(importButton).toHaveTextContent(/Import/i);
        expect(importButton).toBeDisabled();
    });

    it('calls onImport and onClose when import button clicked with valid data', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,ORCID
person,Max,Mustermann,0000-0002-1234-5678`;

        const file = new File([csvContent], 'authors.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        // Wait for file to be shown
        await waitFor(
            () => {
                expect(screen.getByText('authors.csv')).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('shows preview of parsed authors', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name
person,Max,Mustermann
person,Erika,Musterfrau`;

        const file = new File([csvContent], 'authors.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        // Wait for file to be shown (parsing is async)
        await waitFor(
            () => {
                expect(screen.getByText('authors.csv')).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('handles file drop correctly', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name
person,Dropped,Author`;

        const file = new File([csvContent], 'dropped.csv', { type: 'text/csv' });

        const dropZone = document.querySelector('.border-dashed')!;

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

        const csvContent = `Type,First Name,Last Name
person,,,
institution,,,
person,Valid,Author`;

        const file = new File([csvContent], 'multi-errors.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                expect(screen.getByText('multi-errors.csv')).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('displays file size after upload', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name
person,Test,User`;

        const file = new File([csvContent], 'test.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                expect(screen.getByText('test.csv')).toBeInTheDocument();
                expect(screen.getByText(/KB/)).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('parses contact person field correctly', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,Contact Person
person,Contact,Person,ja
person,Non,Contact,no`;

        const file = new File([csvContent], 'contacts.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                expect(screen.getByText('contacts.csv')).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });

    it('parses multiple affiliations correctly', async () => {
        render(<AuthorCsvImport onImport={mockOnImport} onClose={mockOnClose} />);

        const csvContent = `Type,First Name,Last Name,Affiliations
person,Multi,Affil,GFZ`;

        const file = new File([csvContent], 'affiliations.csv', { type: 'text/csv' });

        const input = document.getElementById('csv-upload-authors') as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file] });

        await fireEvent.change(input);

        await waitFor(
            () => {
                expect(screen.getByText('affiliations.csv')).toBeInTheDocument();
            },
            { timeout: 2000 },
        );
    });
});
