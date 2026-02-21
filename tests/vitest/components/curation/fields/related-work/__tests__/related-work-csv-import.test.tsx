import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
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
