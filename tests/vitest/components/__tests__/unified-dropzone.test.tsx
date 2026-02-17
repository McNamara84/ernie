import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const routerMock = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: routerMock,
}));

vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: vi.fn(() => ({ 'X-CSRF-TOKEN': 'test-token' })),
}));

vi.mock('@/routes/dashboard', () => ({
    uploadIgsnCsv: { url: () => '/api/igsn/upload' },
}));

vi.mock('@/routes/igsns', () => ({
    index: { url: () => '/igsns' },
}));

vi.mock('@/components/upload-error-modal', () => ({
    UploadErrorModal: () => null,
}));

vi.mock('@/types/upload', () => ({
    getUploadErrors: vi.fn(() => []),
    hasMultipleErrors: vi.fn(() => false),
}));

import { UnifiedDropzone } from '@/components/unified-dropzone';

describe('UnifiedDropzone', () => {
    it('renders the dropzone area', () => {
        render(<UnifiedDropzone onXmlUpload={vi.fn()} />);
        expect(screen.getByText('Drag & drop files here')).toBeInTheDocument();
    });

    it('shows accepted file types', () => {
        render(<UnifiedDropzone onXmlUpload={vi.fn()} />);
        expect(screen.getByText(/XML \(DataCite\)/)).toBeInTheDocument();
        expect(screen.getByText(/CSV \(IGSN\)/)).toBeInTheDocument();
    });

    it('renders Browse Files button', () => {
        render(<UnifiedDropzone onXmlUpload={vi.fn()} />);
        expect(screen.getByRole('button', { name: 'Browse Files' })).toBeInTheDocument();
    });

    it('has a file input element', () => {
        render(<UnifiedDropzone onXmlUpload={vi.fn()} />);
        const input = document.querySelector('input[type="file"]');
        expect(input).toBeInTheDocument();
    });

    it('accepts .xml, .csv, and .txt files', () => {
        render(<UnifiedDropzone onXmlUpload={vi.fn()} />);
        const input = document.querySelector('input[type="file"]') as HTMLInputElement;
        expect(input.accept).toBe('.xml,.csv,.txt');
    });
});
