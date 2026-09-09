import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkCsvImport from '@/components/curation/fields/related-work/related-work-csv-import';

describe('RelatedWorkCsvImport', () => {
    const onImport = vi.fn();
    const onClose = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    function renderComponent({ onSubmit }: { onSubmit?: () => void } = {}) {
        return render(
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onSubmit?.();
                }}
            >
                <RelatedWorkCsvImport onImport={onImport} onClose={onClose} />
            </form>,
        );
    }

    function getImportButton() {
        const buttons = screen.getAllByRole('button');
        return buttons[buttons.length - 1];
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

    it('marks every local importer action as a non-submit button', () => {
        renderComponent();

        expect(screen.getByRole('button', { name: /close csv import/i })).toHaveAttribute('type', 'button');
        expect(screen.getByRole('button', { name: /download example/i })).toHaveAttribute('type', 'button');
        expect(screen.getByRole('button', { name: /cancel/i })).toHaveAttribute('type', 'button');
        expect(getImportButton()).toHaveAttribute('type', 'button');
    });

    it('calls onClose when close button is clicked', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        renderComponent({ onSubmit });
        await user.click(screen.getByRole('button', { name: /close csv import/i }));
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('calls onClose when cancel button is clicked', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        renderComponent({ onSubmit });
        await user.click(screen.getByRole('button', { name: /cancel/i }));
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('has import button disabled initially', () => {
        renderComponent();
        expect(getImportButton()).toBeDisabled();
        expect(getImportButton()).toHaveTextContent('Import');
    });

    it('renders required columns info text', () => {
        renderComponent();
        expect(screen.getByText(/required columns: identifier, relation_type/i)).toBeInTheDocument();
    });

    it('renders file input with csv accept attribute', () => {
        renderComponent();
        const input = document.getElementById('csv-upload') as HTMLInputElement;
        expect(input).toBeInTheDocument();
        expect(input.accept).toBe('.csv,text/csv');
    });

    it('downloads example CSV on button click', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
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

        renderComponent({ onSubmit });
        await user.click(screen.getByRole('button', { name: /download example/i }));

        expect(createObjectURLSpy).toHaveBeenCalled();
        expect(clickSpy).toHaveBeenCalled();
        expect(revokeObjectURLSpy).toHaveBeenCalled();
        expect(onSubmit).not.toHaveBeenCalled();

        vi.restoreAllMocks();
    });

    it('imports valid CSV rows without submitting the enclosing editor form', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        const file = new File(['identifier,relation_type\n10.1234/example,Cites'], 'related-works.csv', { type: 'text/csv' });

        Object.defineProperty(file, 'text', {
            value: vi.fn().mockResolvedValue('identifier,relation_type\n10.1234/example,Cites'),
        });

        renderComponent({ onSubmit });
        await user.upload(document.getElementById('csv-upload') as HTMLInputElement, file);

        const importButton = screen.getByRole('button', { name: /import 1 items/i });
        await waitFor(() => expect(importButton).toBeEnabled());
        await user.click(importButton);

        expect(onImport).toHaveBeenCalledWith([
            {
                identifier: '10.1234/example',
                identifierType: 'DOI',
                relationType: 'Cites',
            },
        ]);
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    describe('drag and drop visual feedback', () => {
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

        it('ignores non-CSV files on drop', () => {
            const file = new File(['data'], 'test.txt', { type: 'text/plain' });
            renderComponent();

            const dropzone = getDropzone();
            fireEvent.drop(dropzone, { dataTransfer: { files: [file] } });

            expect(screen.getByText(/drop your csv file here/i)).toBeInTheDocument();
        });
    });
});
