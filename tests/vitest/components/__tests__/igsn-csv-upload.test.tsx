import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock routes
vi.mock('@/routes/dashboard', () => ({
    uploadIgsnCsv: { url: () => '/api/igsns/upload' },
}));

// Mock CSRF
const { mockBuildCsrfHeaders } = vi.hoisted(() => ({
    mockBuildCsrfHeaders: vi.fn<() => Record<string, string>>(() => ({ 'X-CSRF-TOKEN': 'test-token', 'X-Requested-With': 'XMLHttpRequest' })),
}));
vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: mockBuildCsrfHeaders,
}));

import { IgsnCsvUpload } from '@/components/igsn-csv-upload';

function createCsvFile(name = 'test.csv', content = 'igsn|title\n123|Test') {
    return new File([content], name, { type: 'text/csv' });
}

describe('IgsnCsvUpload', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.restoreAllMocks();
    });

    describe('idle state', () => {
        it('renders the upload card with title', () => {
            render(<IgsnCsvUpload />);
            expect(screen.getByText('IGSN CSV Upload')).toBeInTheDocument();
        });

        it('renders the dropzone area', () => {
            render(<IgsnCsvUpload />);
            expect(screen.getByTestId('igsn-dropzone')).toBeInTheDocument();
        });

        it('renders the file input (hidden)', () => {
            render(<IgsnCsvUpload />);
            const input = screen.getByTestId('igsn-file-input');
            expect(input).toBeInTheDocument();
            expect(input).toHaveAttribute('type', 'file');
            expect(input).toHaveAttribute('accept', '.csv,.txt');
        });

        it('renders the select file button', () => {
            render(<IgsnCsvUpload />);
            expect(screen.getByTestId('igsn-upload-button')).toHaveTextContent('Select CSV File');
        });

        it('renders the description text', () => {
            render(<IgsnCsvUpload />);
            expect(screen.getByText(/pipe-delimited CSV file/i)).toBeInTheDocument();
        });
    });

    describe('file selection', () => {
        it('triggers file input click when button is clicked', async () => {
            render(<IgsnCsvUpload />);
            const input = screen.getByTestId('igsn-file-input');
            const clickSpy = vi.spyOn(input, 'click');

            await userEvent.click(screen.getByTestId('igsn-upload-button'));
            expect(clickSpy).toHaveBeenCalled();
        });
    });

    describe('uploading state', () => {
        it('shows uploading state with file name during upload', async () => {
            // Make fetch hang to observe the uploading state
            vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}));

            render(<IgsnCsvUpload />);
            const input = screen.getByTestId('igsn-file-input');
            const file = createCsvFile('samples.csv');

            await userEvent.upload(input, file);

            await waitFor(() => {
                expect(screen.getByText(/Uploading samples\.csv/)).toBeInTheDocument();
            });
            expect(screen.getByText('Processing IGSN data...')).toBeInTheDocument();
        });
    });

    describe('success state', () => {
        it('shows success alert after successful upload', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 5 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Upload Successful')).toBeInTheDocument();
            });
            expect(screen.getByText('5 IGSN(s) imported successfully.')).toBeInTheDocument();
        });

        it('shows custom success message when provided', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, message: 'All good!' }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('All good!')).toBeInTheDocument();
            });
        });

        it('shows "Upload Another File" button after success', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 1 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Upload Another File')).toBeInTheDocument();
            });
        });

        it('resets to idle when "Upload Another File" is clicked', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 1 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Upload Another File')).toBeInTheDocument();
            });

            await userEvent.click(screen.getByText('Upload Another File'));
            expect(screen.getByTestId('igsn-dropzone')).toBeInTheDocument();
        });

        it('shows warnings when success with errors', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(
                    JSON.stringify({
                        success: true,
                        created: 3,
                        errors: [{ row: 2, igsn: 'IGSN001', message: 'Duplicate entry' }],
                    }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } },
                ),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Warnings')).toBeInTheDocument();
            });
            expect(screen.getByText(/Row 2.*IGSN001.*Duplicate entry/)).toBeInTheDocument();
        });
    });

    describe('error state', () => {
        it('shows error alert when upload fails', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: false, message: 'Invalid CSV format' }), {
                    status: 422,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Upload Failed')).toBeInTheDocument();
            });
            expect(screen.getByText('Invalid CSV format')).toBeInTheDocument();
        });

        it('shows "Try Again" button after error', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: false, message: 'Error' }), {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Try Again')).toBeInTheDocument();
            });
        });

        it('shows error details when errors array is provided', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(
                    JSON.stringify({
                        success: false,
                        message: 'Validation failed',
                        errors: [
                            { row: 1, igsn: 'IGSN001', message: 'Missing title' },
                            { row: 3, igsn: 'IGSN003', message: 'Invalid format' },
                        ],
                    }),
                    { status: 422, headers: { 'Content-Type': 'application/json' } },
                ),
            );

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText(/Errors \(2\)/)).toBeInTheDocument();
            });
            expect(screen.getByText(/Row 1.*IGSN001.*Missing title/)).toBeInTheDocument();
            expect(screen.getByText(/Row 3.*IGSN003.*Invalid format/)).toBeInTheDocument();
        });

        it('shows network error message when fetch throws', async () => {
            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Network error'));

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Upload Failed')).toBeInTheDocument();
            });
            expect(screen.getByText('Network error')).toBeInTheDocument();
        });

        it('shows CSRF error when token is missing', async () => {
            mockBuildCsrfHeaders.mockReturnValueOnce({});

            render(<IgsnCsvUpload />);
            await userEvent.upload(screen.getByTestId('igsn-file-input'), createCsvFile());

            await waitFor(() => {
                expect(screen.getByText('Upload Failed')).toBeInTheDocument();
            });
            expect(screen.getByText(/CSRF token not found/)).toBeInTheDocument();
        });
    });

    describe('file filtering', () => {
        it('does not upload non-CSV files via input', async () => {
            const fetchSpy = vi.spyOn(globalThis, 'fetch');

            render(<IgsnCsvUpload />);
            const input = screen.getByTestId('igsn-file-input');
            const pngFile = new File(['fake'], 'image.png', { type: 'image/png' });

            await userEvent.upload(input, pngFile);

            // Input has accept=".csv,.txt" so the browser filters, but
            // additionally the component filters by type/extension
            expect(fetchSpy).not.toHaveBeenCalled();
        });
    });
});
