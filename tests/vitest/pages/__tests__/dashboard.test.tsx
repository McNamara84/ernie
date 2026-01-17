import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@testing-library/react';
import { normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { latestVersion } from '@/lib/version';
import Dashboard, { handleXmlFiles } from '@/pages/dashboard';

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

vi.mock('@/routes', () => {
    const makeRoute = (path: string, queryParams?: Record<string, string | number>) => {
        let url = path;
        if (queryParams && Object.keys(queryParams).length > 0) {
            const searchParams = new URLSearchParams();
            Object.entries(queryParams).forEach(([key, value]) => {
                searchParams.append(key, String(value));
            });
            url += `?${searchParams.toString()}`;
        }
        return { url };
    };

    return {
        dashboard: () => makeRoute('/dashboard'),
        editor: (params?: { query?: Record<string, string | number> }) => 
            makeRoute('/editor', params?.query),
        changelog: () => makeRoute('/changelog'),
        about: () => makeRoute('/about'),
        legalNotice: () => makeRoute('/legal-notice'),
    };
});

vi.mock('@/routes/dashboard', () => ({
    uploadXml: { url: () => '/dashboard/upload-xml' },
    uploadIgsnCsv: { url: () => '/dashboard/upload-igsn-csv' },
}));

vi.mock('@/routes/igsns', () => ({
    index: { url: () => '/igsns' },
}));

describe('Dashboard', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({ 
            props: { 
                auth: { user: { name: 'Jane' } }, 
                resourceCount: 17,
                phpVersion: '8.4.12',
                laravelVersion: '12.28.1'
            } 
        });
        handleXmlFilesSpy.mockClear();
    });

    it('greets the user by name', () => {
        render(<Dashboard />);
        expect(screen.getByText(/hello jane!/i)).toBeInTheDocument();
    });

    it('displays the resource count in the statistics card', () => {
        render(<Dashboard />);
        const statsSection = screen.getByText(/datasets from y data centers of z institutions/i);
        const count = within(statsSection).getByText('17');
        expect(count.tagName).toBe('STRONG');
        expect(count).toHaveClass('font-semibold');
        expect(statsSection).toHaveTextContent('17 datasets from y data centers of z institutions');
    });

    it('falls back to zero datasets when the count is unavailable', () => {
        usePageMock.mockReturnValueOnce({ props: { auth: { user: { name: 'Jane' } } } });
        render(<Dashboard />);
        const statsSection = screen.getByText(/datasets from y data centers of z institutions/i);
        expect(within(statsSection).getByText('0')).toBeInTheDocument();
        expect(statsSection).toHaveTextContent('0 datasets from y data centers of z institutions');
    });

    it('links the ERNIE version to the changelog', () => {
        render(<Dashboard />);
        const versionLink = screen.getByRole('link', {
            name: new RegExp(`view changelog for version ${latestVersion}`, 'i'),
        });
        expect(versionLink).toHaveAttribute('href', '/changelog');
        expect(versionLink).toHaveTextContent(latestVersion);
    });

    it('displays PHP version badge with link to PHP release notes', () => {
        render(<Dashboard />);
        const phpVersionLink = screen.getByRole('link', {
            name: /view php 8\.4 release notes/i,
        });
        expect(phpVersionLink).toHaveAttribute('href', 'https://www.php.net/releases/8.4/en.php');
        expect(phpVersionLink).toHaveAttribute('target', '_blank');
        expect(phpVersionLink).toHaveAttribute('rel', 'noopener noreferrer');
        expect(phpVersionLink).toHaveTextContent('8.4.12');
    });

    it('displays Laravel version badge with link to Laravel release notes', () => {
        render(<Dashboard />);
        const laravelVersionLink = screen.getByRole('link', {
            name: /view laravel 12\.x release notes/i,
        });
        expect(laravelVersionLink).toHaveAttribute('href', 'https://laravel.com/docs/12.x/releases');
        expect(laravelVersionLink).toHaveAttribute('target', '_blank');
        expect(laravelVersionLink).toHaveAttribute('rel', 'noopener noreferrer');
        expect(laravelVersionLink).toHaveTextContent('12.28.1');
    });

    it('generates correct PHP release link for major.minor version', () => {
        usePageMock.mockReturnValueOnce({ 
            props: { 
                auth: { user: { name: 'Jane' } }, 
                resourceCount: 17,
                phpVersion: '8.5.3',
                laravelVersion: '12.28.1'
            } 
        });
        render(<Dashboard />);
        const phpVersionLink = screen.getByRole('link', {
            name: /view php 8\.5 release notes/i,
        });
        expect(phpVersionLink).toHaveAttribute('href', 'https://www.php.net/releases/8.5/en.php');
        expect(phpVersionLink).toHaveTextContent('8.5.3');
    });

    it('generates correct Laravel release link for major version', () => {
        usePageMock.mockReturnValueOnce({ 
            props: { 
                auth: { user: { name: 'Jane' } }, 
                resourceCount: 17,
                phpVersion: '8.4.12',
                laravelVersion: '13.5.10'
            } 
        });
        render(<Dashboard />);
        const laravelVersionLink = screen.getByRole('link', {
            name: /view laravel 13\.x release notes/i,
        });
        expect(laravelVersionLink).toHaveAttribute('href', 'https://laravel.com/docs/13.x/releases');
        expect(laravelVersionLink).toHaveTextContent('13.5.10');
    });

    it('falls back to default versions when props are missing', () => {
        usePageMock.mockReturnValueOnce({ 
            props: { 
                auth: { user: { name: 'Jane' } }, 
                resourceCount: 17
            } 
        });
        render(<Dashboard />);
        
        // Should still display badges with fallback values
        const phpBadge = screen.getByText('8.4.12');
        const laravelBadge = screen.getByText('12.28.1');
        
        expect(phpBadge).toBeInTheDocument();
        expect(laravelBadge).toBeInTheDocument();
    });

    it('toggles dropzone highlight on drag events', () => {
        render(<Dashboard />);
        const dropzone = screen.getByTestId('unified-dropzone');
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
        fireEvent.click(screen.getByRole('button', { name: /browse files/i }));
        expect(clickSpy).toHaveBeenCalled();
    });

    it('handles only xml files on drop', () => {
        render(<Dashboard onXmlFiles={handleXmlFilesSpy} />);
        const dropzone = screen.getByTestId('unified-dropzone');
        const xmlFile = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const otherFile = new File(['data'], 'image.png', { type: 'image/png' });
        fireEvent.dragOver(dropzone, { dataTransfer: { files: [xmlFile, otherFile] } });
        fireEvent.drop(dropzone, { dataTransfer: { files: [xmlFile, otherFile] } });
        expect(handleXmlFilesSpy).toHaveBeenCalledTimes(1);
        expect(handleXmlFilesSpy).toHaveBeenCalledWith([xmlFile]);
    });

    it('ignores unsupported file types on drop', () => {
        render(<Dashboard onXmlFiles={handleXmlFilesSpy} />);
        const dropzone = screen.getByTestId('unified-dropzone');
        const imageFile = new File(['data'], 'image.png', { type: 'image/png' });
        fireEvent.dragOver(dropzone, { dataTransfer: { files: [imageFile] } });
        fireEvent.drop(dropzone, { dataTransfer: { files: [imageFile] } });
        // PNG files are not supported (only XML and CSV)
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
        const dropzone = screen.getByTestId('unified-dropzone');
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
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    });

    it('posts xml file with csrf token and redirects to editor with session key', async () => {
        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const sessionKey = 'xml_upload_test123';
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(
                {
                    ok: true,
                    json: async () => ({
                        sessionKey,
                    }),
                } as Response,
            );

        await handleXmlFiles([file]);

        expect(fetchMock).toHaveBeenCalled();
        const [url, options] = fetchMock.mock.calls[0];
        expect(normalizeTestUrl(url as string)).toBe('/dashboard/upload-xml');
        expect((options as RequestInit).headers).toMatchObject({ 'X-CSRF-TOKEN': 'test-token' });
        expect(routerMock.get).toHaveBeenCalled();
        const [redirectUrl] = routerMock.get.mock.calls[0];
        expect(typeof redirectUrl).toBe('string');
        expect(redirectUrl).toBe(`/editor?xmlSession=${sessionKey}`);
        fetchMock.mockRestore();
        routerMock.get.mockReset();
    });

    it('falls back to the XSRF cookie when the meta token is unavailable', async () => {
        document.head.innerHTML = '';
        document.cookie = 'XSRF-TOKEN=cookie-token';

        const file = new File(['<xml></xml>'], 'test.xml', { type: 'text/xml' });
        const sessionKey = 'xml_upload_fallback123';
        const fetchMock = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue({
                ok: true,
                json: async () => ({ sessionKey }),
            } as Response);

        await handleXmlFiles([file]);

        const [, options] = fetchMock.mock.calls[0];
        const headers = (options as RequestInit).headers as Record<string, string>;
        expect(headers['X-CSRF-TOKEN']).toBe('cookie-token');
        expect(headers['X-XSRF-TOKEN']).toBe('cookie-token');
        expect(routerMock.get).toHaveBeenCalledWith(`/editor?xmlSession=${sessionKey}`);

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

    afterEach(() => {
        document.head.innerHTML = '';
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    });
});

