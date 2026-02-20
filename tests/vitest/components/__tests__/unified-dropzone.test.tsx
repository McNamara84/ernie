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
    hasMultipleErrors: (response: Record<string, unknown>) => {
        const errors = response.errors ?? (response.error ? [response.error] : []);
        return Array.isArray(errors) && errors.length >= 3;
    },
}));

import { UnifiedDropzone } from '@/components/unified-dropzone';

function createFile(name: string, type: string, content = 'dummy') {
    return new File([content], name, { type });
}

describe('UnifiedDropzone', () => {
    const mockOnXmlUpload = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        mockOnXmlUpload.mockResolvedValue(undefined);
    });

    describe('idle state', () => {
        it('renders the dropzone container', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            expect(screen.getByTestId('unified-dropzone')).toBeInTheDocument();
        });

        it('renders the file input', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            const input = screen.getByTestId('unified-file-input');
            expect(input).toBeInTheDocument();
            expect(input).toHaveAttribute('type', 'file');
            expect(input).toHaveAttribute('accept', '.xml,.csv,.txt');
        });

        it('renders the browse button', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            expect(screen.getByTestId('unified-upload-button')).toBeInTheDocument();
        });

        it('shows supported file types text', () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            expect(screen.getByText(/XML.*DataCite/)).toBeInTheDocument();
        });
    });

    describe('XML upload', () => {
        it('delegates XML files to onXmlUpload prop', async () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            const input = screen.getByTestId('unified-file-input');
            const xmlFile = createFile('metadata.xml', 'text/xml');

            await userEvent.upload(input, xmlFile);

            await waitFor(() => {
                expect(mockOnXmlUpload).toHaveBeenCalledWith([xmlFile]);
            });
        });

        it('shows uploading state during XML upload', async () => {
            mockOnXmlUpload.mockImplementation(() => new Promise(() => {})); // never resolves

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            const input = screen.getByTestId('unified-file-input');

            await userEvent.upload(input, createFile('test.xml', 'text/xml'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-uploading-state')).toBeInTheDocument();
            });
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

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            const input = screen.getByTestId('unified-file-input');

            await userEvent.upload(input, createFile('samples.csv', 'text/csv'));

            await waitFor(() => {
                expect(globalThis.fetch).toHaveBeenCalledWith('/api/igsns/upload', expect.any(Object));
            });
        });

        it('shows success state after successful CSV upload', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 3 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('test.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-success-state')).toBeInTheDocument();
            });
        });

        it('shows error state when CSV upload fails', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: false, message: 'Invalid format' }), {
                    status: 422,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('bad.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });
        });

        it('shows error when fetch rejects', async () => {
            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Network error'));

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('test.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-error-state')).toBeInTheDocument();
            });
        });
    });

    describe('reset behavior', () => {
        it('resets to idle when "Upload Another File" button is clicked', async () => {
            vi.spyOn(globalThis, 'fetch').mockResolvedValue(
                new Response(JSON.stringify({ success: true, created: 1 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            await userEvent.upload(screen.getByTestId('unified-file-input'), createFile('test.csv', 'text/csv'));

            await waitFor(() => {
                expect(screen.getByTestId('dropzone-success-state')).toBeInTheDocument();
            });

            await userEvent.click(screen.getByText('Upload Another File'));
            expect(screen.getByTestId('unified-dropzone')).toBeInTheDocument();
        });

        it('resets to idle when "Try Again" button is clicked after error', async () => {
            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('fail'));

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
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

            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            const input = screen.getByTestId('unified-file-input');

            await userEvent.upload(input, createFile('photo.png', 'image/png'));

            // Neither fetch nor onXmlUpload should be called for unsupported files
            expect(fetchSpy).not.toHaveBeenCalled();
            expect(mockOnXmlUpload).not.toHaveBeenCalled();
        });
    });

    describe('browse button', () => {
        it('clicks hidden file input when browse button is clicked', async () => {
            render(<UnifiedDropzone onXmlUpload={mockOnXmlUpload} />);
            const input = screen.getByTestId('unified-file-input');
            const clickSpy = vi.spyOn(input, 'click');

            await userEvent.click(screen.getByTestId('unified-upload-button'));
            expect(clickSpy).toHaveBeenCalled();
        });
    });
});
