/**
 * Tests for CsvImportDialog Component
 *
 * Tests the multi-step CSV import dialog for Authors/Contributors:
 * - Step 1: Upload (file selection, example download)
 * - Step 2: Mapping (column to field mapping)
 * - Step 3: Preview (validation, import confirmation)
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Papa from 'papaparse';
import { beforeEach, describe, expect, it, type MockInstance,vi } from 'vitest';

import { CsvImportDialog } from '@/components/curation/fields/csv-import-dialog';

// Mock PapaParse
vi.mock('papaparse', () => ({
    default: {
        parse: vi.fn(),
        unparse: vi.fn(() => 'col1,col2\nval1,val2'),
    },
}));

// Mock URL.createObjectURL
const mockCreateObjectURL = vi.fn(() => 'blob:test-url');
const mockRevokeObjectURL = vi.fn();
Object.defineProperty(URL, 'createObjectURL', { value: mockCreateObjectURL, writable: true });
Object.defineProperty(URL, 'revokeObjectURL', { value: mockRevokeObjectURL, writable: true });

describe('CsvImportDialog', () => {
    const mockOnImport = vi.fn();
    let mockedPapaParse: MockInstance;

    beforeEach(() => {
        vi.clearAllMocks();
        mockedPapaParse = vi.mocked(Papa.parse);
    });

    const defaultProps = {
        onImport: mockOnImport,
        type: 'author' as const,
    };

    describe('Dialog Trigger', () => {
        it('renders the import button', () => {
            render(<CsvImportDialog {...defaultProps} />);

            expect(screen.getByRole('button', { name: /import csv/i })).toBeInTheDocument();
        });

        it('opens dialog when trigger button is clicked', async () => {
            const user = userEvent.setup();
            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            expect(screen.getByRole('dialog')).toBeInTheDocument();
            expect(screen.getByText('Authors aus CSV importieren')).toBeInTheDocument();
        });

        it('shows contributor title when type is contributor', async () => {
            const user = userEvent.setup();
            render(<CsvImportDialog {...defaultProps} type="contributor" />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            expect(screen.getByText('Contributors aus CSV importieren')).toBeInTheDocument();
        });

        it('applies custom trigger class name', () => {
            render(<CsvImportDialog {...defaultProps} triggerClassName="custom-class" />);

            expect(screen.getByRole('button', { name: /import csv/i })).toHaveClass('custom-class');
        });
    });

    describe('Step 1: Upload', () => {
        it('shows upload step description', async () => {
            const user = userEvent.setup();
            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            expect(screen.getByText('CSV-Datei hochladen')).toBeInTheDocument();
        });

        it('shows example download button', async () => {
            const user = userEvent.setup();
            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            expect(screen.getByRole('button', { name: /beispiel-csv herunterladen/i })).toBeInTheDocument();
        });

        it('shows file upload area', async () => {
            const user = userEvent.setup();
            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            expect(screen.getByText('CSV-Datei auswählen')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /datei auswählen/i })).toBeInTheDocument();
        });

        it('has a hidden file input that accepts CSV files', async () => {
            const user = userEvent.setup();
            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            expect(fileInput).toBeInTheDocument();
            expect(fileInput).toHaveAttribute('type', 'file');
            expect(fileInput).toHaveAttribute('accept', '.csv');
        });

        it('downloads example CSV for authors when button is clicked', async () => {
            const user = userEvent.setup();
            
            // Store original methods
            const originalAppendChild = document.body.appendChild.bind(document.body);
            const originalRemoveChild = document.body.removeChild.bind(document.body);
            
            const mockClick = vi.fn();
            let createdLink: HTMLAnchorElement | null = null;
            
            // Override methods
            document.body.appendChild = vi.fn((node: Node) => {
                if (node instanceof HTMLAnchorElement) {
                    createdLink = node;
                    node.click = mockClick;
                    return node;
                }
                return originalAppendChild(node);
            }) as typeof document.body.appendChild;
            
            document.body.removeChild = vi.fn((node: Node) => {
                if (node === createdLink) {
                    return node;
                }
                return originalRemoveChild(node);
            }) as typeof document.body.removeChild;

            render(<CsvImportDialog {...defaultProps} type="author" />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));
            await user.click(screen.getByRole('button', { name: /beispiel-csv herunterladen/i }));

            expect(Papa.unparse).toHaveBeenCalled();
            expect(mockCreateObjectURL).toHaveBeenCalled();
            expect(mockClick).toHaveBeenCalled();

            // Restore original methods
            document.body.appendChild = originalAppendChild;
            document.body.removeChild = originalRemoveChild;
        });
    });

    describe('Step 2: Column Mapping', () => {
        const setupMappingStep = async () => {
            const user = userEvent.setup();
            const csvData = [
                { 'First Name': 'Max', 'Last Name': 'Mustermann', ORCID: '0000-0002-1234-5678' },
                { 'First Name': 'Erika', 'Last Name': 'Musterfrau', ORCID: '' },
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['First Name', 'Last Name', 'ORCID'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            // Simulate file upload
            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            return user;
        };

        it('shows mapping step after file upload', async () => {
            await setupMappingStep();

            expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            expect(screen.getByText(/test.csv/)).toBeInTheDocument();
            expect(screen.getByText(/2 Zeilen/)).toBeInTheDocument();
        });

        it('displays CSV headers for mapping', async () => {
            await setupMappingStep();

            expect(screen.getByText('First Name')).toBeInTheDocument();
            expect(screen.getByText('Last Name')).toBeInTheDocument();
            // ORCID appears both as header and select value, so use getAllByText
            expect(screen.getAllByText('ORCID').length).toBeGreaterThanOrEqual(1);
        });

        it('shows mapping select dropdowns', async () => {
            await setupMappingStep();

            const selects = screen.getAllByRole('combobox');
            expect(selects.length).toBeGreaterThanOrEqual(3);
        });

        it('shows sample data from first row', async () => {
            await setupMappingStep();

            expect(screen.getByText('Max')).toBeInTheDocument();
            expect(screen.getByText('Mustermann')).toBeInTheDocument();
        });

        it('has back button that returns to upload step', async () => {
            const user = await setupMappingStep();

            const backButton = screen.getByRole('button', { name: /zurück/i });
            await user.click(backButton);

            await waitFor(() => {
                expect(screen.getByText('CSV-Datei hochladen')).toBeInTheDocument();
            });
        });

        it('has preview button to proceed', async () => {
            await setupMappingStep();

            expect(screen.getByRole('button', { name: /vorschau anzeigen/i })).toBeInTheDocument();
        });
    });

    describe('Step 3: Preview', () => {
        const setupPreviewStep = async () => {
            const user = userEvent.setup();
            const csvData = [
                { 'First Name': 'Max', 'Last Name': 'Mustermann', ORCID: '0000-0002-1234-5678', Type: 'person' },
                { 'First Name': '', 'Last Name': '', ORCID: '', Type: 'person' }, // Invalid - no name
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['First Name', 'Last Name', 'ORCID', 'Type'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            // Upload file
            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            // Go to preview
            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));

            await waitFor(() => {
                expect(screen.getByText('Vorschau & Import')).toBeInTheDocument();
            });

            return user;
        };

        it('shows preview step after mapping', async () => {
            await setupPreviewStep();

            expect(screen.getByText('Vorschau & Import')).toBeInTheDocument();
        });

        it('displays valid and error row counts', async () => {
            await setupPreviewStep();

            expect(screen.getByText('1 gültig')).toBeInTheDocument();
            expect(screen.getByText('1 Fehler')).toBeInTheDocument();
        });

        it('shows preview table with column headers', async () => {
            await setupPreviewStep();

            expect(screen.getByText('#')).toBeInTheDocument();
            expect(screen.getByText('Status')).toBeInTheDocument();
            expect(screen.getByText('Type')).toBeInTheDocument();
            expect(screen.getByText('Name')).toBeInTheDocument();
        });

        it('shows error message for invalid rows', async () => {
            await setupPreviewStep();

            expect(screen.getByText(/Vorname oder Nachname erforderlich/i)).toBeInTheDocument();
        });

        it('has back button that returns to mapping step', async () => {
            const user = await setupPreviewStep();

            const backButton = screen.getByRole('button', { name: /zurück/i });
            await user.click(backButton);

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });
        });

        it('shows import button with valid row count', async () => {
            await setupPreviewStep();

            expect(screen.getByRole('button', { name: /1 authors importieren/i })).toBeInTheDocument();
        });

        it('calls onImport with valid rows when import button is clicked', async () => {
            const user = await setupPreviewStep();

            const importButton = screen.getByRole('button', { name: /1 authors importieren/i });
            await user.click(importButton);

            expect(mockOnImport).toHaveBeenCalledWith(
                expect.arrayContaining([
                    expect.objectContaining({
                        firstName: 'Max',
                        lastName: 'Mustermann',
                    }),
                ])
            );
        });

        it('closes dialog after successful import', async () => {
            const user = await setupPreviewStep();

            const importButton = screen.getByRole('button', { name: /1 authors importieren/i });
            await user.click(importButton);

            await waitFor(() => {
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            });
        });
    });

    describe('Validation', () => {
        it('shows error for person type without name', async () => {
            const user = userEvent.setup();
            const csvData = [
                { Type: 'person', 'First Name': '', 'Last Name': '', ORCID: '' },
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['Type', 'First Name', 'Last Name', 'ORCID'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));

            await waitFor(() => {
                expect(screen.getByText(/Vorname oder Nachname erforderlich/i)).toBeInTheDocument();
            });
        });

        it('shows error for institution type without organization name', async () => {
            const user = userEvent.setup();
            const csvData = [
                { Type: 'institution', 'Institution Name': '' },
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['Type', 'Institution Name'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));

            await waitFor(() => {
                expect(screen.getByText(/Institution Name erforderlich/i)).toBeInTheDocument();
            });
        });

        it('shows error for invalid ORCID format', async () => {
            const user = userEvent.setup();
            const csvData = [
                { 'First Name': 'Max', 'Last Name': 'Test', ORCID: 'invalid-orcid' },
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['First Name', 'Last Name', 'ORCID'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));

            await waitFor(() => {
                expect(screen.getByText(/Ungültiges ORCID-Format/i)).toBeInTheDocument();
            });
        });
    });

    describe('Auto-detection of column mappings', () => {
        it('auto-maps first name column', async () => {
            const user = userEvent.setup();
            const csvData = [{ Vorname: 'Max', Name: 'Mustermann' }];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['Vorname', 'Name'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            // Preview should work because auto-mapping detected firstName from Vorname
            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));

            await waitFor(() => {
                expect(screen.getByText('Vorschau & Import')).toBeInTheDocument();
            });
        });
    });

    describe('Empty and error cases', () => {
        it('shows alert when CSV file is empty', async () => {
            const user = userEvent.setup();
            const alertMock = vi.spyOn(window, 'alert').mockImplementation(() => {});

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: [],
                    errors: [],
                    meta: { fields: [] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File([''], 'empty.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(alertMock).toHaveBeenCalledWith('CSV file is empty or could not be read.');
            });

            alertMock.mockRestore();
        });

        it('shows alert when no valid rows to import', async () => {
            const user = userEvent.setup();
            const alertMock = vi.spyOn(window, 'alert').mockImplementation(() => {});
            const csvData = [
                { 'First Name': '', 'Last Name': '', Type: 'person' },
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['First Name', 'Last Name', 'Type'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));

            await waitFor(() => {
                expect(screen.getByText('Vorschau & Import')).toBeInTheDocument();
            });

            // Import button should be disabled when no valid rows
            const importButton = screen.getByRole('button', { name: /0 authors importieren/i });
            expect(importButton).toBeDisabled();

            alertMock.mockRestore();
        });
    });

    describe('Special field processing', () => {
        it('processes affiliations as comma-separated list', async () => {
            const user = userEvent.setup();
            const csvData = [
                { 
                    'First Name': 'Max', 
                    'Last Name': 'Mustermann', 
                    Affiliations: 'GFZ Potsdam, University of Berlin' 
                },
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['First Name', 'Last Name', 'Affiliations'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));

            await waitFor(() => {
                expect(screen.getByText('Vorschau & Import')).toBeInTheDocument();
            });

            // Should show affiliations in table
            expect(screen.getByText('GFZ Potsdam, University of Berlin')).toBeInTheDocument();
        });

        it('processes isContact field correctly', async () => {
            const user = userEvent.setup();
            const csvData = [
                { 
                    'First Name': 'Max', 
                    'Last Name': 'Mustermann', 
                    'Contact Person': 'yes' 
                },
            ];

            mockedPapaParse.mockImplementation((file, options) => {
                options.complete({
                    data: csvData,
                    errors: [],
                    meta: { fields: ['First Name', 'Last Name', 'Contact Person'] },
                });
            });

            render(<CsvImportDialog {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            const fileInput = document.getElementById('csv-upload') as HTMLInputElement;
            const file = new File(['test'], 'test.csv', { type: 'text/csv' });
            fireEvent.change(fileInput, { target: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText('Spalten zuordnen')).toBeInTheDocument();
            });

            await user.click(screen.getByRole('button', { name: /vorschau anzeigen/i }));
            await user.click(screen.getByRole('button', { name: /1 authors importieren/i }));

            expect(mockOnImport).toHaveBeenCalledWith(
                expect.arrayContaining([
                    expect.objectContaining({
                        isContact: true,
                    }),
                ])
            );
        });
    });
});
