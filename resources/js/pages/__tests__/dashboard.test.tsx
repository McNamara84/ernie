import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import Dashboard from '../dashboard';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const usePageMock = vi.fn();
const handleXmlFilesSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    usePage: () => usePageMock(),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
}));

describe('Dashboard', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({ props: { auth: { user: { name: 'Jane' } } } });
        handleXmlFilesSpy.mockClear();
    });

    it('greets the user by name', () => {
        render(<Dashboard />);
        expect(screen.getByText(/hello jane!/i)).toBeInTheDocument();
    });

    it('toggles dropzone highlight on drag events', () => {
        render(<Dashboard />);
        const dropzone = screen.getByText(/drag & drop xml files here/i).parentElement as HTMLElement;
        expect(dropzone).toHaveClass('bg-muted');
        fireEvent.dragOver(dropzone);
        expect(dropzone).toHaveClass('bg-accent');
        fireEvent.dragLeave(dropzone);
        expect(dropzone).toHaveClass('bg-muted');
    });

    it('triggers file input when clicking upload button', () => {
        const { container } = render(<Dashboard />);
        const input = container.querySelector('input[type="file"]') as HTMLInputElement;
        const clickSpy = vi.spyOn(input, 'click');
        fireEvent.click(screen.getByRole('button', { name: /upload/i }));
        expect(clickSpy).toHaveBeenCalled();
    });

    it('handles only xml files on drop', () => {
        render(<Dashboard onXmlFiles={handleXmlFilesSpy} />);
        const dropzone = screen.getByText(/drag & drop xml files here/i).parentElement as HTMLElement;
        const xmlFile = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const otherFile = new File(['data'], 'image.png', { type: 'image/png' });
        fireEvent.dragOver(dropzone, { dataTransfer: { files: [xmlFile, otherFile] } });
        fireEvent.drop(dropzone, { dataTransfer: { files: [xmlFile, otherFile] } });
        expect(handleXmlFilesSpy).toHaveBeenCalledTimes(1);
        expect(handleXmlFilesSpy).toHaveBeenCalledWith([xmlFile]);
    });

    it('handles only xml files on file selection', () => {
        const { container } = render(<Dashboard onXmlFiles={handleXmlFilesSpy} />);
        const input = container.querySelector('input[type="file"]') as HTMLInputElement;
        const xmlFile = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const otherFile = new File(['data'], 'note.txt', { type: 'text/plain' });
        fireEvent.change(input, { target: { files: [xmlFile, otherFile] } });
        expect(handleXmlFilesSpy).toHaveBeenCalledTimes(1);
        expect(handleXmlFilesSpy).toHaveBeenCalledWith([xmlFile]);
    });
});

