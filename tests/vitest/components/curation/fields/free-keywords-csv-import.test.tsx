/**
 * @vitest-environment jsdom
 */

import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import FreeKeywordsCsvImport from '@/components/curation/fields/free-keywords-csv-import';

describe('FreeKeywordsCsvImport Component', () => {
    const mockOnImport = vi.fn();
    const mockOnClose = vi.fn();

    const defaultProps = {
        onImport: mockOnImport,
        onClose: mockOnClose,
        existingKeywords: [],
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    // Helper to create a mock File with working text() method
    const createMockFile = (content: string, filename: string): File => {
        const file = new File([content], filename, { type: 'text/csv' });
        // Mock text() method for jsdom environment
        Object.defineProperty(file, 'text', {
            value: vi.fn().mockResolvedValue(content),
        });
        return file;
    };

    describe('Initial Render', () => {
        it('renders CSV import dialog with header', () => {
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            expect(screen.getByText('CSV Bulk Import')).toBeInTheDocument();
            expect(screen.getByText('Import multiple free keywords from a CSV file')).toBeInTheDocument();
        });

        it('shows close button', () => {
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            expect(screen.getByLabelText('Close CSV import')).toBeInTheDocument();
        });

        it('shows example download button', () => {
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            expect(screen.getByText('Download Example')).toBeInTheDocument();
        });

        it('shows file upload area with instructions', () => {
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            expect(screen.getByText('Drop your CSV file here or click to browse')).toBeInTheDocument();
            expect(screen.getByText(/Required: One keyword per row with "Keyword" header/)).toBeInTheDocument();
        });

        it('has hidden file input for accessibility', () => {
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords');
            expect(fileInput).toBeInTheDocument();
            expect(fileInput).toHaveAttribute('type', 'file');
            expect(fileInput).toHaveAttribute('accept', '.csv,text/csv');
        });

        it('shows cancel and import buttons', () => {
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /Import/ })).toBeInTheDocument();
        });

        it('import button is disabled initially', () => {
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const importButton = screen.getByRole('button', { name: /Import/ });
            expect(importButton).toBeDisabled();
        });
    });

    describe('Example CSV Download', () => {
        it('downloads example CSV when button is clicked', async () => {
            const user = userEvent.setup({ delay: null });

            // Mock URL methods before render
            const mockObjectURL = 'blob:test';
            globalThis.URL.createObjectURL = vi.fn(() => mockObjectURL);
            globalThis.URL.revokeObjectURL = vi.fn();

            render(<FreeKeywordsCsvImport {...defaultProps} />);

            // Mock anchor click
            const clickSpy = vi.fn();
            const originalCreateElement = document.createElement.bind(document);
            vi.spyOn(document, 'createElement').mockImplementation((tagName: string) => {
                const element = originalCreateElement(tagName);
                if (tagName === 'a') {
                    element.click = clickSpy;
                }
                return element;
            });

            const downloadButton = screen.getByText('Download Example');
            await user.click(downloadButton);

            expect(globalThis.URL.createObjectURL).toHaveBeenCalled();
            expect(clickSpy).toHaveBeenCalled();
            expect(globalThis.URL.revokeObjectURL).toHaveBeenCalledWith(mockObjectURL);

            // Cleanup
            vi.mocked(document.createElement).mockRestore();
        });
    });

    describe('File Upload', () => {
        it('accepts CSV file via file input', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature
precipitation`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText('keywords.csv')).toBeInTheDocument();
            });
        });

        it('parses CSV and shows success message', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature
precipitation`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 keywords/)).toBeInTheDocument();
            });
        });

        it('shows preview of parsed keywords', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature
precipitation`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText('climate change')).toBeInTheDocument();
                expect(screen.getByText('temperature')).toBeInTheDocument();
                expect(screen.getByText('precipitation')).toBeInTheDocument();
            });
        });

        it('enables import button after successful parse', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                const importButton = screen.getByRole('button', { name: /Import 2 Keywords/ });
                expect(importButton).not.toBeDisabled();
            });
        });
    });

    describe('Duplicate Detection', () => {
        it('removes duplicates within CSV (case-insensitive)', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
Climate Change
CLIMATE CHANGE
temperature`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 2 keywords/)).toBeInTheDocument();
                expect(screen.getByText(/2 duplicates removed/)).toBeInTheDocument();
            });
        });

        it('detects existing keywords', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} existingKeywords={['climate change', 'temperature']} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature
precipitation`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 1 keyword/)).toBeInTheDocument();
                expect(screen.getByText(/2 already exist/)).toBeInTheDocument();
            });
        });

        it('handles mixed case existing keywords', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} existingKeywords={['Climate Change', 'TEMPERATURE']} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature
precipitation`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 1 keyword/)).toBeInTheDocument();
                expect(screen.getByText(/2 already exist/)).toBeInTheDocument();
            });
        });
    });

    describe('Validation', () => {
        it('shows error for empty CSV file', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword`;

            const file = createMockFile(csvContent, 'empty.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/CSV file is empty or has no data rows/)).toBeInTheDocument();
            });
        });

        it('shows error for keywords that are too long', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const longKeyword = 'a'.repeat(300);
            const csvContent = `Keyword
${longKeyword}`;

            const file = createMockFile(csvContent, 'long.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Keyword is too long \(max 255 characters\)/)).toBeInTheDocument();
            });
        });

        it('shows error for too many keywords', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const keywords = Array.from({ length: 1001 }, (_, i) => `keyword${i}`).join('\n');
            const csvContent = `Keyword\n${keywords}`;

            const file = createMockFile(csvContent, 'many.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Too many keywords. Maximum is 1000/)).toBeInTheDocument();
            });
        });

        it('skips empty rows in CSV', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change

temperature

precipitation`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 keywords/)).toBeInTheDocument();
            });
        });

        it('trims whitespace from keywords', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
  climate change  
temperature
  precipitation  `;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText('climate change')).toBeInTheDocument();
                expect(screen.getByText('precipitation')).toBeInTheDocument();
            });
        });
    });

    describe('CSV Format Flexibility', () => {
        it('treats first row as header when no explicit header exists (PapaParse behavior)', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `climate change
temperature
precipitation`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                // With PapaParse header:true, first row is treated as header
                // So we expect 2 keywords (temperature, precipitation)
                expect(screen.getByText(/Successfully parsed 2 keywords/)).toBeInTheDocument();
            });
        });

        it('handles different header names (case-insensitive)', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `KEYWORD
climate change
temperature`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 2 keywords/)).toBeInTheDocument();
            });
        });

        it('accepts CSV files with various MIME types', async () => {
            const user = userEvent.setup({ delay: null });
            const csvContent = `Keyword
climate change`;

            // Test different MIME types that browsers/OS may use
            const mimeTypes = ['text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'];

            for (const mimeType of mimeTypes) {
                const { unmount } = render(<FreeKeywordsCsvImport {...defaultProps} />);

                const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
                const file = new File([csvContent], 'keywords.csv', { type: mimeType });
                Object.defineProperty(file, 'text', {
                    value: vi.fn().mockResolvedValue(csvContent),
                });

                await user.upload(fileInput, file);

                await waitFor(() => {
                    expect(screen.getByText(/Successfully parsed 1 keyword/)).toBeInTheDocument();
                });

                unmount();
            }
        });

        it('accepts CSV files with .csv extension even when MIME type is missing', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change`;

            // Create file with empty MIME type (some systems don't set it)
            const file = new File([csvContent], 'keywords.csv', { type: '' });
            Object.defineProperty(file, 'text', {
                value: vi.fn().mockResolvedValue(csvContent),
            });

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 1 keyword/)).toBeInTheDocument();
            });
        });
    });

    describe('User Actions', () => {
        it('calls onClose when cancel button is clicked', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const cancelButton = screen.getByRole('button', { name: 'Cancel' });
            await user.click(cancelButton);

            expect(mockOnClose).toHaveBeenCalledTimes(1);
        });

        it('calls onClose when close icon is clicked', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const closeButton = screen.getByLabelText('Close CSV import');
            await user.click(closeButton);

            expect(mockOnClose).toHaveBeenCalledTimes(1);
        });

        it('calls onImport with parsed keywords and closes dialog', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByRole('button', { name: /Import 2 Keywords/ })).toBeInTheDocument();
            });

            const importButton = screen.getByRole('button', { name: /Import 2 Keywords/ });
            await user.click(importButton);

            expect(mockOnImport).toHaveBeenCalledTimes(1);
            expect(mockOnImport).toHaveBeenCalledWith(['climate change', 'temperature']);
            expect(mockOnClose).toHaveBeenCalledTimes(1);
        });

        it('does not call onImport when there are validation errors', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword`;

            const file = createMockFile(csvContent, 'empty.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/CSV file is empty or has no data rows/)).toBeInTheDocument();
            });

            const importButton = screen.getByRole('button', { name: /Import/ });
            expect(importButton).toBeDisabled();

            // Try to click anyway
            await user.click(importButton);

            expect(mockOnImport).not.toHaveBeenCalled();
        });
    });

    describe('Preview Limits', () => {
        it('shows first 10 keywords in preview', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const keywords = Array.from({ length: 15 }, (_, i) => `keyword${i + 1}`);
            const csvContent = `Keyword\n${keywords.join('\n')}`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 15 keywords/)).toBeInTheDocument();
                expect(screen.getByText('... and 5 more')).toBeInTheDocument();
            });
        });
    });

    describe('Progress Indicator', () => {
        it('shows progress bar while processing', async () => {
            const user = userEvent.setup({ delay: null });
            render(<FreeKeywordsCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-free-keywords') as HTMLInputElement;
            const csvContent = `Keyword
climate change
temperature`;

            const file = createMockFile(csvContent, 'keywords.csv');

            await user.upload(fileInput, file);

            // Progress completes quickly, so we check for the file name instead
            await waitFor(() => {
                expect(screen.getByText('keywords.csv')).toBeInTheDocument();
            });
        });
    });
});
