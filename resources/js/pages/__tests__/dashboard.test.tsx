import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import Dashboard, { handleXmlFiles } from '../dashboard';
import { latestVersion } from '@/lib/version';
import { applyBasePathToRoutes, __testing as basePathTesting } from '@/lib/base-path';
import { uploadXml as uploadXmlRoute } from '@/routes/dashboard';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const usePageMock = vi.fn();
const handleXmlFilesSpy = vi.fn();
const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    usePage: () => usePageMock(),
    router: routerMock,
    Link: ({ href, children, ...props }: { href: unknown; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => {
        const resolvedHref =
            typeof href === 'string'
                ? href
                : href && typeof href === 'object' && 'url' in (href as Record<string, unknown>)
                  ? String((href as { url: string }).url)
                  : '';

        return (
            <a href={resolvedHref} {...props}>
                {children}
            </a>
        );
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/routes', async () => {
    const { withBasePath } = await import('@/lib/base-path');

    const makeRoute = (path: string) => ({ url: withBasePath(path) });

    return {
        dashboard: () => makeRoute('/dashboard'),
        curation: () => makeRoute('/curation'),
        changelog: () => makeRoute('/changelog'),
        about: () => makeRoute('/about'),
        legalNotice: () => makeRoute('/legal-notice'),
    };
});

describe('Dashboard', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({ props: { auth: { user: { name: 'Jane' } } } });
        handleXmlFilesSpy.mockClear();
    });

    afterEach(() => {
        document.head.innerHTML = '';
        basePathTesting.resetBasePathCache();
    });

    it('greets the user by name', () => {
        render(<Dashboard />);
        expect(screen.getByText(/hello jane!/i)).toBeInTheDocument();
    });

    it('links the ERNIE version to the changelog', () => {
        render(<Dashboard />);
        const versionLink = screen.getByRole('link', {
            name: new RegExp(`view changelog for version ${latestVersion}`, 'i'),
        });
        expect(versionLink).toHaveAttribute('href', '/changelog');
        expect(versionLink).toHaveTextContent(latestVersion);
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

    it('shows an error alert when upload fails', async () => {
        handleXmlFilesSpy.mockRejectedValue(new Error('Invalid file'));
        render(<Dashboard onXmlFiles={handleXmlFilesSpy} />);
        const dropzone = screen.getByText(/drag & drop xml files here/i).parentElement as HTMLElement;
        const xmlFile = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        fireEvent.dragOver(dropzone, { dataTransfer: { files: [xmlFile] } });
        fireEvent.drop(dropzone, { dataTransfer: { files: [xmlFile] } });
        await screen.findByText('Invalid file');
        expect(screen.getByRole('alert')).toHaveTextContent('Invalid file');
    });
});

describe('handleXmlFiles', () => {
    beforeEach(() => {
        routerMock.get.mockReset();
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
        basePathTesting.resetBasePathCache();
    });

    it('posts xml file with csrf token and redirects to curation with DOI, Year, Version, Language, Resource Type, Titles and Licenses', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                {
                    ok: true,
                    json: async () => ({
                        doi: '10.1234/abc',
                        year: '2024',
                        version: '1.0',
                        language: 'en',
                        resourceType: '1',
                        titles: [
                            { title: 'Example Title', titleType: 'main-title' },
                            { title: 'Example Subtitle', titleType: 'subtitle' },
                            { title: 'Example TranslatedTitle', titleType: 'translated-title' },
                            { title: 'Example AlternativeTitle', titleType: 'alternative-title' },
                        ],
                        licenses: ['CC-BY-4.0', 'MIT'],
                    }),
                } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/dashboard/upload-xml');
        expect((options as RequestInit).headers).toMatchObject({ 'X-CSRF-TOKEN': 'test-token' });
        expect(routerMock.get).toHaveBeenCalledWith('/curation', {
            doi: '10.1234/abc',
            year: '2024',
            version: '1.0',
            language: 'en',
            resourceType: '1',
            'titles[0][title]': 'Example Title',
            'titles[0][titleType]': 'main-title',
            'titles[1][title]': 'Example Subtitle',
            'titles[1][titleType]': 'subtitle',
            'titles[2][title]': 'Example TranslatedTitle',
            'titles[2][titleType]': 'translated-title',
            'titles[3][title]': 'Example AlternativeTitle',
            'titles[3][titleType]': 'alternative-title',
            'licenses[0]': 'CC-BY-4.0',
            'licenses[1]': 'MIT',
        });
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('redirects to curation with a single main title', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                {
                    ok: true,
                    json: async () => ({
                        titles: [
                            { title: 'A mandatory Event', titleType: 'main-title' },
                        ],
                    }),
                } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        expect(routerMock.get).toHaveBeenCalledWith('/curation', {
            'titles[0][title]': 'A mandatory Event',
            'titles[0][titleType]': 'main-title',
        });
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('redirects to curation without DOI, Year, Version, Language or Resource Type when none is returned', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                { ok: true, json: async () => ({ doi: null, year: null, version: null, language: null, resourceType: null }) } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        expect(routerMock.get).toHaveBeenCalledWith('/curation', {});
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('redirects to curation with Year and Language when DOI and Resource Type are missing', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                { ok: true, json: async () => ({ doi: null, year: '2023', language: 'en', resourceType: null }) } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        expect(routerMock.get).toHaveBeenCalledWith('/curation', { year: '2023', language: 'en' });
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('redirects to curation with Version and Language when DOI, Year and Resource Type are missing', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                { ok: true, json: async () => ({ doi: null, year: null, version: '2.0', language: 'en', resourceType: null }) } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        expect(routerMock.get).toHaveBeenCalledWith('/curation', { version: '2.0', language: 'en' });
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('redirects to curation with Language when DOI, Year, Version and Resource Type are missing', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                { ok: true, json: async () => ({ doi: null, year: null, version: null, language: 'de', resourceType: null }) } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        expect(routerMock.get).toHaveBeenCalledWith('/curation', { language: 'de' });
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('redirects to curation with Resource Type when DOI, Year, Version and Language are missing', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                { ok: true, json: async () => ({ doi: null, year: null, version: null, language: null, resourceType: '1' }) } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        expect(routerMock.get).toHaveBeenCalledWith('/curation', { resourceType: '1' });
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('honors a configured base path for uploads and redirects', async () => {
        basePathTesting.setMetaBasePath('/ernie');
        applyBasePathToRoutes({ uploadXml: uploadXmlRoute });
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                {
                    ok: true,
                    json: async () => ({
                        doi: null,
                        year: null,
                        version: null,
                        language: null,
                        resourceType: null,
                        titles: [],
                        licenses: [],
                    }),
                } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalledWith('/ernie/dashboard/upload-xml', expect.any(Object));
        expect(routerMock.get).toHaveBeenCalledWith('/ernie/curation', expect.any(Object));
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('throws when csrf token is missing', async () => {
        document.head.innerHTML = '';
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi.spyOn(global, 'fetch');

        await expect(handleXmlFiles([file])).rejects.toThrow('CSRF token not found');
        expect(fetchMock).not.toHaveBeenCalled();

        fetchMock.mockRestore();
    });

    it('throws server error message', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue({ ok: false, json: async () => ({ message: 'Bad request' }) } as Response);

        await expect(handleXmlFiles([file])).rejects.toThrow('Bad request');
        expect(routerMock.get).not.toHaveBeenCalled();

        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('logs and throws when fetch fails', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const fetchMock = vi.spyOn(global, 'fetch').mockRejectedValue(new Error('network down'));
        const consoleErrorMock = vi.spyOn(console, 'error').mockImplementation(() => {});

        await expect(handleXmlFiles([file])).rejects.toThrow('Upload failed: network down');
        expect(consoleErrorMock).toHaveBeenCalled();

        fetchMock.mockRestore();
        consoleErrorMock.mockRestore();
    });
});

