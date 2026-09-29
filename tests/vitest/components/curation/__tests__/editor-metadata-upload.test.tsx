import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { EditorMetadataUpload } from '@/components/curation/editor-metadata-upload';

describe('EditorMetadataUpload', () => {
    const onImported = vi.fn();
    const onImportingChange = vi.fn();

    beforeEach(() => {
        onImported.mockClear();
        onImportingChange.mockClear();
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.head.innerHTML = '';
    });

    it.each([
        ['metadata.xml', '/editor/upload-xml/preview'],
        ['metadata.json', '/editor/upload-json/preview'],
        ['metadata.jsonld', '/editor/upload-json/preview'],
    ])('parses %s in place without navigating', async (filename, route) => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue({
                ok: true,
                json: async () => ({ success: true, metadata: { titles: [{ title: 'Imported', titleType: 'main-title' }] } }),
            });
        vi.stubGlobal('fetch', fetchMock);
        render(<EditorMetadataUpload onImported={onImported} onImportingChange={onImportingChange} />);

        const file = new File(['test'], filename);
        fireEvent.change(screen.getByTestId('editor-metadata-file-input'), { target: { files: [file] } });

        await waitFor(() => expect(onImported).toHaveBeenCalledWith({ titles: [{ title: 'Imported', titleType: 'main-title' }] }));
        expect(fetchMock).toHaveBeenCalledWith(route, expect.objectContaining({ method: 'POST', credentials: 'same-origin' }));
        expect(onImportingChange).toHaveBeenCalledWith(true);
        expect(onImportingChange).toHaveBeenCalledWith(false);
        expect(screen.getByRole('status')).toHaveTextContent('Existing entries were kept');
    });

    it('can be collapsed and reopened using its keyboard-accessible heading button', async () => {
        render(<EditorMetadataUpload onImported={onImported} onImportingChange={onImportingChange} />);
        const toggle = screen.getByRole('button', { name: /upload datacite metadata/i });
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await userEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByTestId('editor-metadata-file-input')).not.toBeInTheDocument();
        await userEvent.click(toggle);
        expect(screen.getByTestId('editor-metadata-file-input')).toBeInTheDocument();
    });

    it('keeps the form unchanged after an invalid or rejected file', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: false, json: async () => ({ success: false, message: 'Invalid DataCite document' }) });
        vi.stubGlobal('fetch', fetchMock);
        render(<EditorMetadataUpload onImported={onImported} onImportingChange={onImportingChange} />);

        fireEvent.change(screen.getByTestId('editor-metadata-file-input'), { target: { files: [new File(['x'], 'notes.csv')] } });
        expect(screen.getByRole('alert')).toHaveTextContent('Select a DataCite XML, JSON, or JSON-LD file.');
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.change(screen.getByTestId('editor-metadata-file-input'), { target: { files: [new File(['x'], 'invalid.xml')] } });
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Invalid DataCite document'));
        expect(onImported).not.toHaveBeenCalled();
    });
});
