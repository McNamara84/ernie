import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia
const { mockVisit } = vi.hoisted(() => ({ mockVisit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { visit: mockVisit },
}));

// Mock routes
vi.mock('@/routes/dashboard', () => ({
    uploadIgsnCsv: { url: () => '/api/igsns/upload' },
    uploadJson: { url: () => '/dashboard/upload-json' },
}));
vi.mock('@/routes/igsns', () => ({
    index: { url: () => '/igsns' },
}));

// Mock CSRF
vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: () => ({ 'X-CSRF-TOKEN': 'test-token', 'X-Requested-With': 'XMLHttpRequest' }),
}));

// Mock sonner - the module exports toast as a function with methods
const { mockToastSuccess, mockToastError, mockToastFn } = vi.hoisted(() => {
    const mockToastSuccess = vi.fn();
    const mockToastError = vi.fn();
    const mockToastFn = vi.fn();
    return { mockToastSuccess, mockToastError, mockToastFn };
});
vi.mock('sonner', () => {
    const toast = Object.assign(mockToastFn, {
        success: mockToastSuccess,
        error: mockToastError,
    });
    return { toast };
});

// Mock UploadErrorModal
vi.mock('@/components/upload-error-modal', () => ({
    UploadErrorModal: ({ open }: { open: boolean }) => (open ? <div data-testid="error-modal">Error Modal</div> : null),
}));

// Mock upload types
vi.mock('@/types/upload', () => ({
    getUploadErrors: (response: Record<string, unknown>) => response.errors ?? (response.error ? [response.error] : []),
    hasMultipleErrors: (response: Record<string, unknown>, threshold = 3) => {
        const errors = response.errors ?? (response.error ? [response.error] : []);
        return Array.isArray(errors) && errors.length > threshold;
    },
}));

import { UnifiedDropzone } from '@/components/unified-dropzone';

function createFile(name: string, type: string, content = 'dummy') {
    return new File([content], name, { type });
}

describe('UnifiedDropzone', () => {
    const mockOnXmlUpload = vi.fn();
    const mockOnJsonUpload = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        mockOnXmlUpload.mockImplementation(async (files: File[]) => ({
            success: true,
            uploadKind: 'datacite',
            filename: files[0]?.name ?? 'metadata.xml',
            resourceId: '1',
            editorUrl: '/editor?resourceId=1',
        }));
        mockOnJsonUpload.mockImplementation(async (files: File[]) => ({
            success: true,
            uploadKind: 'datacite',
            filename: files[0]?.name ?? 'metadata.json',
            resourceId: '2',
            editorUrl: '/editor?resourceId=2',
        }));
    });

    describe('idle state', () => {
        it('renders the dropzone container', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            expect(screen.getByTestId('unified-dropzone')).toBeInTheDocument();
        });

        it('renders the file input', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');
            expect(input).toBeInTheDocument();
            expect(input).toHaveAttribute('type', 'file');
            expect(input).toHaveAttribute('accept', '.xml,.json,.jsonld,.csv,.txt');
        });

        it('renders the browse button', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            expect(screen.getByTestId('unified-upload-button')).toBeInTheDocument();
        });

        it('shows supported file types text', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            expect(screen.getByText(/DataCite.*XML.*JSON/)).toBeInTheDocument();
        });
    });

    describe('XML upload', () => {
        it('delegates XML files to onXmlUpload prop', async () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');
            const xmlFile = createFile('metadata.xml', 'text/xml');

            await userEvent.upload(input, xmlFile);

            await waitFor(() => {
                expect(mockOnXmlUpload).toHaveBeenCalledWith([xmlFile]);
            });
        });

        it('shows uploading state during XML upload', async () => {
            mockOnXmlUpload.mockImplementation(() => new Promise(() => {})); // never resolves

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');

            await userEvent.upload(input, createFile('test.xml', 'text/xml'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-uploading-state')).toBeInTheDocument();
            });
        });
        it('shows a DataCite success state and opens the editor only on click', async () => {
            mockOnXmlUpload.mockResolvedValue({
                success: true,
                uploadKind: 'datacite',
                filename: 'metadata.xml',
                resourceId: '42',
                editorUrl: '/editor?resourceId=42',
            });

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('metadata.xml', 'text/xml'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-success-state')).toBeInTheDocument();
            });

            expect(screen.getByText('DataCite upload complete')).toBeInTheDocument();
            expect(screen.getByText(/Draft resource #42 is ready to review/i)).toBeInTheDocument();
            expect(mockVisit).not.toHaveBeenCalled();

            await userEvent.click(screen.getByRole('button', { name: /open in editor/i }));
            expect(mockVisit).toHaveBeenCalledWith('/editor?resourceId=42');
        });
        it('shows an error when XML upload returns no editor target', async () => {
            mockOnXmlUpload.mockResolvedValue(undefined);

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('metadata.xml', 'text/xml'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });

            expect(screen.getByTestId('dropzone-error-alert')).toHaveTextContent(/no editor target was returned/i);
            expect(screen.queryByText('Row Errors:')).not.toBeInTheDocument();
            expect(screen.queryByTestId('dropzone-success-state')).not.toBeInTheDocument();
            expect(mockVisit).not.toHaveBeenCalled();
        });
    });

    describe('JSON upload', () => {
        it('delegates JSON files to onJsonUpload prop', async () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');
            const jsonFile = createFile('metadata.json', 'application/json');

            await userEvent.upload(input, jsonFile);

            await waitFor(() => {
                expect(mockOnJsonUpload).toHaveBeenCalledWith([jsonFile]);
            });
        });

        it('delegates .jsonld files to onJsonUpload prop', async () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');
            const jsonLdFile = createFile('metadata.jsonld', 'application/ld+json');

            await userEvent.upload(input, jsonLdFile);

            await waitFor(() => {
                expect(mockOnJsonUpload).toHaveBeenCalledWith([jsonLdFile]);
            });
        });

        it('shows uploading state during JSON upload', async () => {
            mockOnJsonUpload.mockImplementation(() => new Promise(() => {})); // never resolves

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');

            await userEvent.upload(input, createFile('test.json', 'application/json'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-uploading-state')).toBeInTheDocument();
            });
        });
        it('shows a DataCite success state for JSON without navigating automatically', async () => {
            mockOnJsonUpload.mockResolvedValue({
                success: true,
                uploadKind: 'datacite',
                filename: 'metadata.json',
                resourceId: '77',
                editorUrl: '/editor?resourceId=77',
            });

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('metadata.json', 'application/json'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-success-state')).toBeInTheDocument();
            });

            expect(screen.getByText('DataCite upload complete')).toBeInTheDocument();
            expect(mockVisit).not.toHaveBeenCalled();
        });
        it('shows an error when JSON upload returns no editor target', async () => {
            mockOnJsonUpload.mockResolvedValue(undefined);

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('metadata.json', 'application/json'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });

            expect(screen.getByTestId('dropzone-error-alert')).toHaveTextContent(/no editor target was returned/i);
            expect(screen.queryByText('Row Errors:')).not.toBeInTheDocument();
            expect(screen.queryByTestId('dropzone-success-state')).not.toBeInTheDocument();
            expect(mockVisit).not.toHaveBeenCalled();
        });
    });

    describe('CSV upload', () => {
        it('uploads CSV files via fetch', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 3 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');

            await userEvent.upload(input, createFile('samples.csv', 'text/csv'));

            await waitFor(() => {
                expect(globalThis.fetch).toHaveBeenCalledWith('/api/igsns/upload', expect.any(Object));
            });
        });

        it('shows success state after successful CSV upload without navigating automatically', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 3, filename: 'test.csv' }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('test.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-success-state')).toBeInTheDocument();
            });

            expect(screen.getByText('IGSN import complete')).toBeInTheDocument();
            expect(screen.getByTestId('dropzone-success-alert')).toHaveTextContent(/test\.csv imported 3 IGSN resource/i);
            expect(screen.getByRole('button', { name: /view igsns/i })).toBeInTheDocument();
            expect(mockVisit).not.toHaveBeenCalled();

            await userEvent.click(screen.getByRole('button', { name: /view igsns/i }));
            expect(mockVisit).toHaveBeenCalledWith('/igsns');
        });

        it('shows error state when CSV upload fails', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: false, message: 'Invalid format' }), {
                    status: 422,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('bad.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });
        });

        it('shows row errors for structured CSV upload failures', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(
                    JSON.stringify({
                        success: false,
                        message: 'Invalid CSV data',
                        errors: [{ row: 2, igsn: 'TEST001', message: 'Duplicate IGSN' }],
                    }),
                    {
                        status: 422,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('bad.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });

            expect(screen.getByText('Row Errors:')).toBeInTheDocument();
            expect(screen.getByText(/Row 2:/)).toBeInTheDocument();
            expect(screen.getByText('TEST001')).toBeInTheDocument();
            expect(screen.getByText('Duplicate IGSN')).toBeInTheDocument();
        });
        it('shows the error modal for CSV upload failures with many row errors', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(
                    JSON.stringify({
                        success: false,
                        message: 'Multiple CSV rows failed',
                        errors: [
                            { row: 2, igsn: 'TEST001', message: 'Duplicate IGSN' },
                            { row: 3, igsn: 'TEST002', message: 'Missing sample type' },
                            { row: 4, igsn: 'TEST003', message: 'Invalid date' },
                            { row: 5, igsn: 'TEST004', message: 'Missing material' },
                        ],
                    }),
                    {
                        status: 422,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('bad.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });

            expect(screen.getByTestId('error-modal')).toBeInTheDocument();
        });
        it('shows error when fetch rejects', async () => {
            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Network error'));

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('test.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });
        });
    });

    describe('reset behavior', () => {
        it('resets to idle when "Upload another file" button is clicked', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 1 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('test.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-success-state')).toBeInTheDocument();
            });

            await userEvent.click(screen.getByText('Upload another file'));
            expect(screen.getByTestId('unified-dropzone')).toBeInTheDocument();
        });

        it('resets to idle when "Try Again" button is clicked after error', async () => {
            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('fail'));

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('test.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });

            await userEvent.click(screen.getByText('Try Again'));
            expect(screen.getByTestId('unified-dropzone')).toBeInTheDocument();
        });
    });

    describe('file type detection', () => {
        it('rejects unsupported file types', async () => {
            const fetchSpy = vi.spyOn(globalThis, 'fetch');

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');

            await userEvent.upload(input, createFile('photo.png', 'image/png'));

            // Neither fetch nor onXmlUpload should be called for unsupported files
            expect(fetchSpy).not.toHaveBeenCalled();
            expect(mockOnXmlUpload).not.toHaveBeenCalled();
        });
    });

    describe('browse button', () => {
        it('clicks hidden file input when browse button is clicked', async () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} onJsonUpload={mockOnJsonUpload} />);
            const input = screen.getByTestId('unified-file-input');
            const clickSpy = vi.spyOn(input, 'click');

            await userEvent.click(screen.getByTestId('unified-upload-button'));
            expect(clickSpy).toHaveBeenCalled();
        });
    });
});
