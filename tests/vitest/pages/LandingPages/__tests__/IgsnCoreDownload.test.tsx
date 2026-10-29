/** @vitest-environment jsdom */
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { DownloadMetadataSection } from '@/pages/LandingPages/components/DownloadMetadataSection';
import type { LandingPageMetadataLink } from '@/types/landing-page';

const link: LandingPageMetadataLink = {
    format: 'igsn-core-2.0',
    standard: 'IGSN 2.0 Core',
    label: 'IGSN 2.0 Core (Preview)',
    url: '/10.58052/TEST/sample/metadata/igsn-core-2.0.xml',
    mediaType: 'application/xml',
    profile: '/schemas/igsn/core.xsd',
};
const action = () => screen.getByRole('button', { name: 'Download IGSN 2.0 Core metadata as XML (Preview)' });
afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('IGSN Core metadata download', () => {
    it('is absent from ordinary resource pages and does not invent a public endpoint', () => {
        render(<DownloadMetadataSection resourceId={1} supportsIgsnCore />);
        expect(screen.queryByText('IGSN 2.0 Core (Preview)')).not.toBeInTheDocument();
    });

    it('offers the preview dialog without requesting metadata', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        render(<DownloadMetadataSection resourceId={1} isPreview supportsIgsnCore />);
        await userEvent.click(screen.getByTitle('Download as IGSN 2.0 Core XML (Preview)'));
        expect(await screen.findByRole('dialog')).toHaveTextContent('Metadata download unavailable');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('downloads a validated XML blob and releases its URL', async () => {
        const create = vi.fn().mockReturnValue('blob:test');
        const revoke = vi.fn();
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: create });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: revoke });
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        const fetch = vi.fn().mockResolvedValue(new Response('<resource/>', { headers: { 'Content-Type': 'application/xml; charset=UTF-8' } }));
        vi.stubGlobal('fetch', fetch);
        render(<DownloadMetadataSection resourceId={1} metadataLinks={[link]} />);
        expect(screen.getByRole('link', { name: 'IGSN Core schema' })).toHaveAttribute('href', link.profile);
        await userEvent.click(action());
        await waitFor(() => expect(click).toHaveBeenCalledOnce());
        expect(fetch).toHaveBeenCalledWith(
            link.url,
            expect.objectContaining({ headers: { Accept: 'application/xml' }, signal: expect.any(AbortSignal) }),
        );
        expect(create).toHaveBeenCalledOnce();
        await waitFor(() => expect(revoke).toHaveBeenCalledWith('blob:test'), { timeout: 2000 });
    });

    it.each([422, 500, 404, 429, 200])('shows errors instead of downloading a JSON response (%s)', async (status) => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response('{"message":"private server path"}', { status, headers: { 'Content-Type': 'application/json' } })),
        );
        render(<DownloadMetadataSection resourceId={1} metadataLinks={[link]} />);
        await userEvent.click(action());
        expect(await screen.findByRole('alert')).toHaveTextContent(
            status === 422 ? 'Required metadata is missing or unsupported.' : 'Please try again later.',
        );
        expect(screen.getByRole('alert')).not.toHaveTextContent('private server path');
        expect(click).not.toHaveBeenCalled();
        expect(action()).not.toBeDisabled();
    });

    it('allows retry after a network error', async () => {
        const fetch = vi.fn().mockRejectedValue(new TypeError('Network error'));
        vi.stubGlobal('fetch', fetch);
        render(<DownloadMetadataSection resourceId={1} metadataLinks={[link]} />);
        await userEvent.click(action());
        expect(await screen.findByRole('alert')).toHaveTextContent('check your connection');
        await userEvent.click(action());
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('prevents duplicate downloads and cancels pending requests on unmount', async () => {
        const fetch = vi.fn().mockImplementation(() => new Promise(() => {}));
        vi.stubGlobal('fetch', fetch);
        const view = render(<DownloadMetadataSection resourceId={1} metadataLinks={[link]} />);
        await userEvent.dblClick(action());
        expect(fetch).toHaveBeenCalledOnce();
        expect(action()).toBeDisabled();
        const signal = fetch.mock.calls[0][1].signal as AbortSignal;
        view.unmount();
        expect(signal.aborted).toBe(true);
    });
});
