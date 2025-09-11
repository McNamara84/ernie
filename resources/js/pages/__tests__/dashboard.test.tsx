import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import Dashboard, { handleXmlFiles } from '../dashboard';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const usePageMock = vi.fn();
const handleXmlFilesSpy = vi.fn();
const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    usePage: () => usePageMock(),
    router: routerMock,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
    about: () => '/about',
    legalNotice: () => '/legal-notice',
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

    it('ignores non-xml files on drop', () => {
        render(<Dashboard onXmlFiles={handleXmlFilesSpy} />);
        const dropzone = screen.getByText(/drag & drop xml files here/i).parentElement as HTMLElement;
        const textFile = new File(['data'], 'readme.txt', { type: 'text/plain' });
        fireEvent.dragOver(dropzone, { dataTransfer: { files: [textFile] } });
        fireEvent.drop(dropzone, { dataTransfer: { files: [textFile] } });
        expect(handleXmlFilesSpy).not.toHaveBeenCalled();
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

describe('handleXmlFiles', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    });

    it('posts xml file with csrf token and redirects to curation with DOI', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue({ ok: true, json: async () => ({ doi: '10.1234/abc' }) } as Response);

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/dashboard/upload-xml');
        expect((options as RequestInit).headers).toMatchObject({ 'X-CSRF-TOKEN': 'test-token' });
        expect(routerMock.get).toHaveBeenCalledWith('/curation', { doi: '10.1234/abc' });
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });
});

