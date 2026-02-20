import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkCsvImport from '@/components/curation/fields/related-work/related-work-csv-import';

describe('RelatedWorkCsvImport', () => {
    const onImport = vi.fn();
    const onClose = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    function renderComponent() {
        return render(<RelatedWorkCsvImport onImport={onImport} onClose={onClose} />);
    }

    function getImportButton() {
        // The Import button is the last button in the footer
        const buttons = screen.getAllByRole('button');
        return buttons[buttons.length - 1];
    }

    function getCsvInput(): HTMLInputElement {
        return document.getElementById('csv-upload') as HTMLInputElement;
    }

    async function uploadCsv(content: string) {
        const file = new File([content], 'test.csv', { type: 'text/csv' });
        const input = getCsvInput();
        await act(async () => {
            Object.defineProperty(input, 'files', { value: [file], configurable: true });
            fireEvent.change(input);
            // Allow File.text() Promise and state updates to resolve
            await new Promise((resolve) => setTimeout(resolve, 50));
        });
    }

    it('renders the CSV import UI with header and instructions', () => {
        renderComponent();
        expect(screen.getByText('CSV Bulk Import')).toBeInTheDocument();
        expect(screen.getByText(/import multiple related works/i)).toBeInTheDocument();
        expect(screen.getByText(/drop your csv file here/i)).toBeInTheDocument();
    });

    it('renders the example download button', () => {
        renderComponent();
        expect(screen.getByRole('button', { name: /download example/i })).toBeInTheDocument();
    });

    it('calls onClose when close button is clicked', async () => {
        const user = userEvent.setup();
        renderComponent();
        await user.click(screen.getByRole('button', { name: /close csv import/i }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('calls onClose when cancel button is clicked', async () => {
        const user = userEvent.setup();
        renderComponent();
        await user.click(screen.getByRole('button', { name: /cancel/i }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('has import button disabled initially', () => {
        renderComponent();
        expect(getImportButton()).toBeDisabled();
    });

    it('downloads example CSV on button click', async () => {
        const user = userEvent.setup();
        const createObjectURLSpy = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test');
        const revokeObjectURLSpy = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
        const clickSpy = vi.fn();

        const originalCreateElement = document.createElement.bind(document);
        vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
            if (tag === 'a') {
                return { href: '', download: '', click: clickSpy } as unknown as HTMLAnchorElement;
            }
            return originalCreateElement(tag);
        });

        renderComponent();
        await user.click(screen.getByRole('button', { name: /download example/i }));

        expect(createObjectURLSpy).toHaveBeenCalled();
        expect(clickSpy).toHaveBeenCalled();
        expect(revokeObjectURLSpy).toHaveBeenCalled();

        vi.restoreAllMocks();
    });

    describe('CSV parsing', () => {
        it('parses a valid CSV with identifier_type column', async () => {
            renderComponent();
            await uploadCsv(`identifier,identifier_type,relation_type
10.1234/test,DOI,Cites
https://example.org,URL,References`);

            await waitFor(() => {
                expect(screen.getByText(/successfully parsed 2 related work/i)).toBeInTheDocument();
            });

            expect(getImportButton()).toBeEnabled();
        });

        it('parses a valid CSV without identifier_type (auto-detection)', async () => {
            renderComponent();
            await uploadCsv(`identifier,relation_type
10.1234/test,Cites
https://example.org,References`);

            await waitFor(() => {
                expect(screen.getByText(/successfully parsed 2 related work/i)).toBeInTheDocument();
            });
        });

        it('auto-detects DOI from URL format and normalizes', async () => {
            renderComponent();
            await uploadCsv(`identifier,relation_type
https://doi.org/10.1234/test,Cites`);

            await waitFor(() => {
                expect(screen.getByText(/successfully parsed 1 related work/i)).toBeInTheDocument();
            });

            // DOI URL should be normalized — shows identifier without URL prefix
            expect(screen.getByText(/10\.1234\/test/)).toBeInTheDocument();
            expect(screen.getByText(/\(DOI\)/)).toBeInTheDocument();
        });

        it('shows error for empty CSV', async () => {
            renderComponent();
            await uploadCsv(`identifier,relation_type`);

            await waitFor(() => {
                expect(screen.getByText(/csv file is empty or has no data rows/i)).toBeInTheDocument();
            });
        });

        it('shows error for missing required columns', async () => {
            renderComponent();
            await uploadCsv(`name,value
test,123`);

            await waitFor(() => {
                expect(screen.getByText(/missing required columns/i)).toBeInTheDocument();
            });
        });

        it('shows validation error for invalid relation_type', async () => {
            renderComponent();
            await uploadCsv(`identifier,relation_type
10.1234/test,InvalidType`);

            await waitFor(() => {
                expect(screen.getByText(/invalid relation type/i)).toBeInTheDocument();
            });
        });

        it('shows validation error for invalid identifier_type', async () => {
            renderComponent();
            await uploadCsv(`identifier,identifier_type,relation_type
10.1234/test,INVALID,Cites`);

            await waitFor(() => {
                expect(screen.getByText(/invalid identifier type/i)).toBeInTheDocument();
            });
        });

        it('calls onImport with parsed data and closes on import click', async () => {
            renderComponent();
            await uploadCsv(`identifier,relation_type
10.1234/test,Cites`);

            await waitFor(() => {
                expect(getImportButton()).toBeEnabled();
            });

            const user = userEvent.setup();
            await user.click(getImportButton());

            expect(onImport).toHaveBeenCalledWith([
                {
                    identifier: '10.1234/test',
                    identifierType: 'DOI',
                    relationType: 'Cites',
                },
            ]);
            expect(onClose).toHaveBeenCalled();
        });
    });

    describe('drag and drop', () => {
        function getDropzone(): HTMLElement {
            return document.querySelector('.border-dashed') as HTMLElement;
        }

        it('highlights drop zone on drag over', () => {
            renderComponent();
            const dropzone = getDropzone();
            fireEvent.dragOver(dropzone);
            expect(dropzone.className).toContain('border-primary');
        });

        it('removes highlight on drag leave', () => {
            renderComponent();
            const dropzone = getDropzone();
            fireEvent.dragOver(dropzone);
            fireEvent.dragLeave(dropzone);
            expect(dropzone.className).not.toContain('border-primary');
        });

        it('parses dropped CSV file', async () => {
            const csv = `identifier,relation_type
10.1234/test,Cites`;
            const file = new File([csv], 'test.csv', { type: 'text/csv' });
            renderComponent();

            const dropzone = getDropzone();
            fireEvent.drop(dropzone, { dataTransfer: { files: [file] } });

            await waitFor(() => {
                expect(screen.getByText(/successfully parsed 1 related work/i)).toBeInTheDocument();
            });
        });

        it('ignores non-CSV files on drop', () => {
            const file = new File(['data'], 'test.txt', { type: 'text/plain' });
            renderComponent();

            const dropzone = getDropzone();
            fireEvent.drop(dropzone, { dataTransfer: { files: [file] } });

            // Should still show the upload prompt
            expect(screen.getByText(/drop your csv file here/i)).toBeInTheDocument();
        });
    });

    describe('preview', () => {
        it('shows preview of first 5 items with overflow message', async () => {
            const rows = Array.from({ length: 7 }, (_, i) => `10.1234/test${i},Cites`).join('\n');
            renderComponent();
            await uploadCsv(`identifier,relation_type\n${rows}`);

            await waitFor(() => {
                expect(screen.getByText(/successfully parsed 7 related works/i)).toBeInTheDocument();
            });

            expect(screen.getByText(/preview \(first 5\)/i)).toBeInTheDocument();
            expect(screen.getByText(/\.\.\. and 2 more/i)).toBeInTheDocument();
        });
    });
});
